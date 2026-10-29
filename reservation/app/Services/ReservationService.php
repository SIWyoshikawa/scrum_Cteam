<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\EquipmentRepository;
use App\Repositories\LoanRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\ReservationRepository;
use App\Support\Database;
use App\Support\HttpException;

/**
 * 予約・承認・貸出・返却（REQ-F-005〜011）
 *
 * 同時操作の守り（詳細設計 5.3 / 5.4）
 *   - 在庫に関わる処理（申請・承認・貸出）は、トランザクション内で「備品行 → 予約行」の順にロックしてから再確認する
 *   - 状態の変更は「今の状態が○○のときだけ」の条件付きUPDATE。1行変わらなければ 409
 *   - 監査ログ・お知らせも同じトランザクションで書く（途中で失敗したら全部取り消し）
 *
 * 利用者に見せるエラーは HttpException（409/422）。メッセージは画面にそのまま出る。
 */
final class ReservationService
{
    public function __construct(
        private readonly Database $db,
        private readonly ReservationRepository $reservations,
        private readonly EquipmentRepository $equipment,
        private readonly LoanRepository $loans,
        private readonly NotificationRepository $notifications,
        private readonly AuditLogger $audit,
        private readonly ReservationRules $rules,
    ) {
    }

    /**
     * 予約申請（一般利用者）
     * @param array<int,string> $rawQuantities
     * @return array{id:int|null, errors:list<string>}
     */
    public function request(int $userId, string $start, string $end, string $purpose, array $rawQuantities, string $today): array
    {
        return $this->db->transaction(function ($pdo) use ($userId, $start, $end, $purpose, $rawQuantities, $today): array {
            // 同じ人が同時に申請して上限件数をすり抜けないよう、本人の行をロック
            $pdo->prepare('SELECT id FROM users WHERE id = :id FOR UPDATE')->execute([':id' => $userId]);

            $check = $this->rules->validate(
                $start, $end, $purpose, $rawQuantities, $today,
                $this->reservations->countActive($userId),
                $this->loans->userHasOverdue($userId, $today),
            );
            if ($check['errors'] !== []) {
                return ['id' => null, 'errors' => $check['errors']];
            }
            $items = $check['items'];
            ksort($items);

            $locked = $this->equipment->lockMany(array_keys($items));
            $errors = $this->stockErrors($items, $locked, $start, $end, $today, null);
            if ($errors !== []) {
                return ['id' => null, 'errors' => $errors];
            }

            $id = $this->reservations->create($userId, $start, $end, trim($purpose), $items);
            $this->audit->log($userId, 'reservation_requested', 'reservation', $id, [
                'after' => ['status' => 'pending', 'start_date' => $start, 'end_date' => $end, 'items' => $items],
            ]);
            return ['id' => $id, 'errors' => []];
        });
    }

    /** 本人による取消（申請中のみ。REQ-F-010） */
    public function cancelByOwner(int $userId, int $reservationId): void
    {
        $this->db->transaction(function () use ($userId, $reservationId): void {
            $r = $this->reservations->lock($reservationId);
            if ($r === null || $r['user_id'] !== $userId) {
                throw new HttpException(404);
            }
            if ($r['status'] !== 'pending' || !$this->reservations->transition($reservationId, 'pending', 'cancelled', [
                'cancelled_by' => $userId, 'cancellation_reason' => '本人による取消',
            ])) {
                throw new HttpException(409, '取り消せるのは「申請中」の予約だけです。');
            }
            $this->audit->log($userId, 'reservation_cancelled', 'reservation', $reservationId, [
                'before' => ['status' => 'pending'], 'after' => ['status' => 'cancelled'], 'by' => 'owner',
            ]);
        });
    }

    /** 承認（管理者。REQ-F-006 / 009） */
    public function approve(int $adminId, int $reservationId, string $comment, string $today): void
    {
        $this->db->transaction(function () use ($adminId, $reservationId, $comment, $today): void {
            $r = $this->lockWithEquipment($reservationId);
            $this->guardNotSelf($adminId, $r);
            if ($r['status'] !== 'pending') {
                throw new HttpException(409, '「申請中」ではないため承認できません。画面を開き直してください。');
            }
            if ($r['start_date'] < $today) {
                throw new HttpException(409, '利用開始日を過ぎているため承認できません。');
            }
            $items = $this->itemMap($reservationId);
            $locked = $this->equipment->lockMany(array_keys($items));
            $errors = $this->stockErrors($items, $locked, $r['start_date'], $r['end_date'], $today, $reservationId);
            if ($errors !== []) {
                throw new HttpException(409, '承認できません：' . implode(' ', $errors));
            }
            if (!$this->reservations->transition($reservationId, 'pending', 'approved', [
                'reviewed_by' => $adminId, 'review_reason' => $comment === '' ? null : $comment,
            ])) {
                throw new HttpException(409);
            }
            $this->audit->log($adminId, 'reservation_approved', 'reservation', $reservationId, [
                'before' => ['status' => 'pending'], 'after' => ['status' => 'approved'], 'reason' => $comment,
            ]);
            $this->notifications->add($r['user_id'], $reservationId, 'approved',
                "予約（{$r['start_date']}〜{$r['end_date']}）が承認されました。利用開始日に窓口で受け取ってください。");
        });
    }

    /** 却下（管理者。理由必須） */
    public function reject(int $adminId, int $reservationId, string $reason): void
    {
        $reason = $this->requireReason($reason, '却下の理由');
        $this->db->transaction(function () use ($adminId, $reservationId, $reason): void {
            $r = $this->reservations->lock($reservationId) ?? throw new HttpException(404);
            $this->guardNotSelf($adminId, $r);
            if (!$this->reservations->transition($reservationId, 'pending', 'rejected', [
                'reviewed_by' => $adminId, 'review_reason' => $reason,
            ])) {
                throw new HttpException(409, '「申請中」ではないため却下できません。画面を開き直してください。');
            }
            $this->audit->log($adminId, 'reservation_rejected', 'reservation', $reservationId, [
                'before' => ['status' => 'pending'], 'after' => ['status' => 'rejected'], 'reason' => $reason,
            ]);
            $this->notifications->add($r['user_id'], $reservationId, 'rejected',
                "予約（{$r['start_date']}〜{$r['end_date']}）は却下されました。理由：{$reason}");
        });
    }

    /** 承認済みの取消（管理者。理由必須。貸出中は不可） */
    public function cancelByAdmin(int $adminId, int $reservationId, string $reason): void
    {
        $reason = $this->requireReason($reason, '取消の理由');
        $this->db->transaction(function () use ($adminId, $reservationId, $reason): void {
            $r = $this->reservations->lock($reservationId) ?? throw new HttpException(404);
            if (!$this->reservations->transition($reservationId, 'approved', 'cancelled', [
                'cancelled_by' => $adminId, 'cancellation_reason' => $reason,
            ])) {
                throw new HttpException(409, '取り消せるのは「承認済み」の予約だけです（貸出中は返却で終わらせてください）。');
            }
            $this->audit->log($adminId, 'reservation_cancelled', 'reservation', $reservationId, [
                'before' => ['status' => 'approved'], 'after' => ['status' => 'cancelled'], 'by' => 'admin', 'reason' => $reason,
            ]);
            $this->notifications->add($r['user_id'], $reservationId, 'cancelled',
                "承認済みの予約（{$r['start_date']}〜{$r['end_date']}）が取り消されました。理由：{$reason}");
        });
    }

    /** 貸出（管理者。REQ-F-007）。利用開始日〜返却予定日の間だけ */
    public function checkout(int $adminId, int $reservationId, string $note, string $today): void
    {
        $note = trim($note);
        if (mb_strlen($note) > 500) {
            throw new HttpException(422, 'メモは500文字以内で入力してください。');
        }
        $this->db->transaction(function () use ($adminId, $reservationId, $note, $today): void {
            $r = $this->lockWithEquipment($reservationId);
            if ($r['status'] !== 'approved') {
                throw new HttpException(409, '貸出できるのは「承認済み」の予約だけです。');
            }
            if ($today < $r['start_date']) {
                throw new HttpException(409, "利用開始日（{$r['start_date']}）より前は貸し出せません。");
            }
            if ($today > $r['end_date']) {
                throw new HttpException(409, '返却予定日を過ぎているため貸し出せません。');
            }
            $items = $this->itemMap($reservationId);
            $locked = $this->equipment->lockMany(array_keys($items));
            // 前の人がまだ返していない場合などに備えて、手渡しの直前にも数を確かめる
            $errors = $this->stockErrors($items, $locked, $today, $r['end_date'], $today, $reservationId);
            if ($errors !== []) {
                throw new HttpException(409, '貸出できません：' . implode(' ', $errors));
            }
            $loanItems = [];
            foreach ($items as $eid => $qty) {
                $loanItems[] = ['equipment_id' => $eid, 'quantity' => $qty];
            }
            $loanId = $this->loans->create($reservationId, $adminId, $r['end_date'], $note === '' ? null : $note, $loanItems);
            if (!$this->reservations->transition($reservationId, 'approved', 'on_loan')) {
                throw new HttpException(409);
            }
            $this->audit->log($adminId, 'loan_checked_out', 'reservation', $reservationId, [
                'before' => ['status' => 'approved'], 'after' => ['status' => 'on_loan', 'loan_id' => $loanId],
                'due_date' => $r['end_date'], 'note' => $note,
            ]);
        });
    }

    /**
     * 返却（管理者。一部ずつ返せる）。全部返ったら貸出を returned、予約を completed にする
     * @param array<int,string> $rawQuantities loan_item_id => 今回返す数
     * @return bool 全部返ったら true
     */
    public function returnItems(int $adminId, int $reservationId, array $rawQuantities, string $note): bool
    {
        $note = trim($note);
        if (mb_strlen($note) > 500) {
            throw new HttpException(422, '状態メモは500文字以内で入力してください。');
        }
        return $this->db->transaction(function () use ($adminId, $reservationId, $rawQuantities, $note): bool {
            $r = $this->reservations->lock($reservationId) ?? throw new HttpException(404);
            $loan = $this->loans->findByReservation($reservationId, true);
            if ($r['status'] !== 'on_loan' || $loan === null || $loan['status'] !== 'on_loan') {
                throw new HttpException(409, '返却できるのは「貸出中」の予約だけです。');
            }
            $items = $this->loans->items($loan['id'], true);
            $returned = [];
            foreach ($items as $it) {
                $raw = trim($rawQuantities[$it['id']] ?? '');
                if ($raw === '' || $raw === '0') {
                    continue;
                }
                if (!preg_match('/^[1-9][0-9]{0,4}$/', $raw) || (int) $raw > $it['outstanding']) {
                    throw new HttpException(422, "{$it['name']} の返却数は 1〜{$it['outstanding']} で入力してください。");
                }
                if (!$this->loans->recordReturn($it['id'], (int) $raw, $adminId, $note === '' ? null : $note)) {
                    throw new HttpException(409);
                }
                $returned[$it['equipment_id']] = (int) $raw;
            }
            if ($returned === []) {
                throw new HttpException(422, '返却する数量を1つ以上入力してください。');
            }

            $done = array_sum(array_column($this->loans->items($loan['id']), 'outstanding')) === 0;
            if ($done) {
                if (!$this->loans->markReturned($loan['id']) || !$this->reservations->transition($reservationId, 'on_loan', 'completed')) {
                    throw new HttpException(409);
                }
            }
            $this->audit->log($adminId, $done ? 'loan_returned' : 'loan_partially_returned', 'reservation', $reservationId, [
                'returned' => $returned, 'note' => $note,
                'after' => ['status' => $done ? 'completed' : 'on_loan'],
            ]);
            return $done;
        });
    }

    /**
     * 期限切れにする（一覧を開いたときに実行）
     *   申請中のまま利用開始日を過ぎた → expired
     *   承認済みのまま返却予定日を過ぎても受け取りがない → expired
     */
    public function expireStale(string $today): int
    {
        return $this->db->transaction(function () use ($today): int {
            $n = 0;
            foreach ($this->reservations->lockStale($today) as $r) {
                if ($this->reservations->transition($r['id'], $r['status'], 'expired')) {
                    $this->audit->log(null, 'reservation_expired', 'reservation', $r['id'], [
                        'before' => ['status' => $r['status']], 'after' => ['status' => 'expired'], 'today' => $today,
                    ]);
                    $n++;
                }
            }
            return $n;
        });
    }

    // ---------------------------------------------------------------

    /**
     * 在庫チェック：備品が存在し・利用停止でなく・期間中に押さえられていない数が足りるか
     * @param array<int,int> $items @param array<int,array<string,mixed>> $locked
     * @return list<string>
     */
    private function stockErrors(array $items, array $locked, string $start, string $end, string $today, ?int $excludeId): array
    {
        $errors = [];
        $reserved = $this->equipment->reservedQuantities(array_keys($items), $start, $end, $today, $excludeId);
        foreach ($items as $eid => $qty) {
            $e = $locked[$eid] ?? null;
            if ($e === null) {
                $errors[] = '選んだ備品が見つかりません。画面を開き直してください。';
                continue;
            }
            if ($e['status'] !== 'available') {
                $errors[] = "{$e['name']} は利用停止中です。";
                continue;
            }
            $free = $e['total_quantity'] - ($reserved[$eid] ?? 0);
            if ($qty > $free) {
                $errors[] = "{$e['name']} はこの期間に {$qty} 個用意できません（空き " . max(0, $free) . " 個）。";
            }
        }
        return $errors;
    }

    /** 備品行 → 予約行の順でロック（どの処理も同じ順にしてデッドロックを防ぐ） */
    private function lockWithEquipment(int $reservationId): array
    {
        $ids = array_keys($this->itemMap($reservationId));
        $this->equipment->lockMany($ids);
        return $this->reservations->lock($reservationId) ?? throw new HttpException(404);
    }

    /** @return array<int,int> equipment_id => quantity */
    private function itemMap(int $reservationId): array
    {
        $map = [];
        foreach ($this->reservations->items($reservationId) as $it) {
            $map[$it['equipment_id']] = $it['quantity'];
        }
        ksort($map);
        return $map;
    }

    /** 自己承認の禁止（自分の申請を自分で承認・却下しない） */
    private function guardNotSelf(int $adminId, array $r): void
    {
        if ($r['user_id'] === $adminId) {
            throw new HttpException(403, '自分の申請は承認・却下できません。別の管理担当者が行ってください。');
        }
    }

    private function requireReason(string $reason, string $label): string
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new HttpException(422, "{$label}を入力してください。");
        }
        if (mb_strlen($reason) > 500) {
            throw new HttpException(422, "{$label}は500文字以内で入力してください。");
        }
        return $reason;
    }
}

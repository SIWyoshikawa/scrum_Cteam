<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * reservations / reservation_items（オーナー作成のテーブル）
 *
 * 状態（reservations.status）
 *   pending   申請中      → approved / rejected / cancelled / expired
 *   approved  承認済み    → on_loan / cancelled（管理者・理由必須） / expired（返却予定日を過ぎても受け取りなし）
 *   on_loan   貸出中      → completed（全数返却）
 *   rejected / cancelled / expired / completed は終わり（変えない）
 * 延滞は状態ではなく「貸出中で返却予定日が今日より前」という派生表示（REQ-F-011）。
 */
final class ReservationRepository
{
    public const STATUS_LABELS = [
        'pending'   => '申請中',
        'approved'  => '承認済み',
        'rejected'  => '却下',
        'cancelled' => '取消',
        'expired'   => '期限切れ',
        'on_loan'   => '貸出中',
        'completed' => '返却済み',
    ];

    /** 進行中（1人あたりの上限件数に数える状態） */
    public const ACTIVE = ['pending', 'approved', 'on_loan'];

    private const SELECT = 'SELECT r.id, r.user_id, r.start_date, r.end_date, r.purpose, r.status,
                                   r.reviewed_by, r.reviewed_at, r.review_reason,
                                   r.cancelled_by, r.cancellation_reason, r.created_at, r.updated_at,
                                   u.user_id AS login_id, u.name AS user_name,
                                   rv.name AS reviewer_name
                              FROM reservations r
                              JOIN users u ON u.id = r.user_id
                              LEFT JOIN users rv ON rv.id = r.reviewed_by';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param array<int,int> $items equipment_id => quantity */
    public function create(int $userId, string $start, string $end, string $purpose, array $items): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO reservations (user_id, start_date, end_date, purpose, status, created_at, updated_at)
             VALUES (:uid, :start, :end, :purpose, 'pending', UTC_TIMESTAMP(), UTC_TIMESTAMP())"
        );
        $stmt->execute([':uid' => $userId, ':start' => $start, ':end' => $end, ':purpose' => $purpose]);
        $id = (int) $this->pdo->lastInsertId();

        $item = $this->pdo->prepare(
            'INSERT INTO reservation_items (reservation_id, equipment_id, quantity) VALUES (:rid, :eid, :qty)'
        );
        foreach ($items as $equipmentId => $qty) {
            $item->execute([':rid' => $id, ':eid' => $equipmentId, ':qty' => $qty]);
        }
        return $id;
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE r.id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row === false ? null : self::map($row);
    }

    /**
     * 本人の予約だけを取る（他人のIDを指定されたら null → 404。IDOR対策 DD 6.4）
     * @return array<string,mixed>|null
     */
    public function findOwned(int $id, int $userId): ?array
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE r.id = :id AND r.user_id = :uid');
        $stmt->execute([':id' => $id, ':uid' => $userId]);
        $row = $stmt->fetch();
        return $row === false ? null : self::map($row);
    }

    /** トランザクション内で予約行をロックして読み直す */
    public function lock(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM reservations WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() === false ? null : $this->find($id);
    }

    /** @return list<array{equipment_id:int,name:string,quantity:int,equipment_status:string,storage_location:?string}> */
    public function items(int $reservationId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ri.equipment_id, e.name, ri.quantity, e.status AS equipment_status, e.storage_location
               FROM reservation_items ri
               JOIN equipment e ON e.id = ri.equipment_id
              WHERE ri.reservation_id = :rid
              ORDER BY e.name, e.id'
        );
        $stmt->execute([':rid' => $reservationId]);
        return array_map(static function (array $r): array {
            $r['equipment_id'] = (int) $r['equipment_id'];
            $r['quantity'] = (int) $r['quantity'];
            return $r;
        }, $stmt->fetchAll());
    }

    /**
     * 一覧（本人用・管理者用）。備品名は「マイク×2、ノートPC×1」の形でまとめて返す。
     * @param list<string> $statuses 空なら全部
     * @return list<array<string,mixed>>
     */
    public function list(?int $userId, array $statuses, int $limit = 200): array
    {
        $sql = self::SELECT . ' WHERE 1 = 1';
        $params = [];
        if ($userId !== null) {
            $sql .= ' AND r.user_id = :uid';
            $params[':uid'] = $userId;
        }
        $statuses = array_values(array_intersect($statuses, array_keys(self::STATUS_LABELS)));
        if ($statuses !== []) {
            $names = [];
            foreach ($statuses as $i => $s) {
                $names[] = ":s{$i}";
                $params[":s{$i}"] = $s;
            }
            $sql .= ' AND r.status IN (' . implode(',', $names) . ')';
        }
        $sql .= ' ORDER BY r.start_date DESC, r.id DESC LIMIT ' . max(1, min($limit, 500));
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = array_map(self::map(...), $stmt->fetchAll());
        return $this->attachSummaries($rows);
    }

    public function countActive(int $userId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM reservations WHERE user_id = :uid AND status IN ('pending','approved','on_loan')"
        );
        $stmt->execute([':uid' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    /** @return array<string,int> 状態 => 件数（管理メニュー用） */
    public function countByStatus(): array
    {
        $out = array_fill_keys(array_keys(self::STATUS_LABELS), 0);
        foreach ($this->pdo->query('SELECT status, COUNT(*) AS n FROM reservations GROUP BY status')->fetchAll() as $r) {
            $out[(string) $r['status']] = (int) $r['n'];
        }
        return $out;
    }

    /**
     * 条件付きで状態を変える（今の状態が $from のときだけ1行変わる＝二重操作や競合を防ぐ）
     * @param array<string,int|string|null> $extra 追加で更新する列（列名は固定の許可リストのみ）
     */
    public function transition(int $id, string $from, string $to, array $extra = []): bool
    {
        $allowed = ['reviewed_by', 'review_reason', 'cancelled_by', 'cancellation_reason'];
        $set = 'status = :to, updated_at = UTC_TIMESTAMP()';
        $params = [':to' => $to, ':id' => $id, ':from' => $from];
        foreach ($extra as $col => $value) {
            if (!in_array($col, $allowed, true)) {
                throw new \LogicException('更新できない列: ' . $col);
            }
            $set .= ", {$col} = :{$col}";
            $params[":{$col}"] = $value;
        }
        if (array_key_exists('reviewed_by', $extra)) {
            $set .= ', reviewed_at = UTC_TIMESTAMP()';
        }
        $stmt = $this->pdo->prepare("UPDATE reservations SET {$set} WHERE id = :id AND status = :from");
        $stmt->execute($params);
        return $stmt->rowCount() === 1;
    }

    /**
     * 期限切れの予約を見つける（ロック付き）
     *   申請中のまま利用開始日を過ぎた / 承認済みのまま返却予定日を過ぎても受け取りがない
     * @return list<array{id:int,status:string,user_id:int}>
     */
    public function lockStale(string $today): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT id, status, user_id FROM reservations
              WHERE (status = 'pending' AND start_date < :t1) OR (status = 'approved' AND end_date < :t2)
              ORDER BY id FOR UPDATE"
        );
        $stmt->execute([':t1' => $today, ':t2' => $today]);
        return array_map(static fn (array $r) => [
            'id' => (int) $r['id'], 'status' => (string) $r['status'], 'user_id' => (int) $r['user_id'],
        ], $stmt->fetchAll());
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function attachSummaries(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $names = [];
        $params = [];
        foreach ($rows as $i => $r) {
            $names[] = ":r{$i}";
            $params[":r{$i}"] = $r['id'];
        }
        $stmt = $this->pdo->prepare(
            'SELECT ri.reservation_id, e.name, ri.quantity
               FROM reservation_items ri JOIN equipment e ON e.id = ri.equipment_id
              WHERE ri.reservation_id IN (' . implode(',', $names) . ')
              ORDER BY e.name, e.id'
        );
        $stmt->execute($params);
        $summary = [];
        foreach ($stmt->fetchAll() as $it) {
            $summary[(int) $it['reservation_id']][] = $it['name'] . '×' . $it['quantity'];
        }
        foreach ($rows as &$r) {
            $r['items_summary'] = implode('、', $summary[$r['id']] ?? []);
        }
        unset($r);
        return $rows;
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function map(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['user_id'] = (int) $row['user_id'];
        $row['reviewed_by'] = $row['reviewed_by'] === null ? null : (int) $row['reviewed_by'];
        return $row;
    }
}

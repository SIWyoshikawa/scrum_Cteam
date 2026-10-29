<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * loans / loan_items / returns（オーナー作成のテーブル）
 *   貸出：予約1件につき loans 1行（reservation_id は UNIQUE）、備品ごとに loan_items
 *   返却：一部ずつ返せる。返した分を returns に追記し、loan_items.returned_quantity を増やす
 */
final class LoanRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @param list<array{equipment_id:int,quantity:int}> $items */
    public function create(int $reservationId, int $operatorId, string $dueDate, ?string $note, array $items): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO loans (reservation_id, handed_out_by, lent_at, due_date, status, note)
             VALUES (:rid, :op, UTC_TIMESTAMP(), :due, 'on_loan', :note)"
        );
        $stmt->execute([':rid' => $reservationId, ':op' => $operatorId, ':due' => $dueDate, ':note' => $note]);
        $loanId = (int) $this->pdo->lastInsertId();

        $item = $this->pdo->prepare(
            'INSERT INTO loan_items (loan_id, equipment_id, quantity, returned_quantity) VALUES (:lid, :eid, :qty, 0)'
        );
        foreach ($items as $it) {
            $item->execute([':lid' => $loanId, ':eid' => $it['equipment_id'], ':qty' => $it['quantity']]);
        }
        return $loanId;
    }

    /** @return array<string,mixed>|null */
    public function findByReservation(int $reservationId, bool $forUpdate = false): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT l.id, l.reservation_id, l.handed_out_by, l.lent_at, l.due_date, l.status, l.note,
                    u.name AS handed_out_by_name
               FROM loans l JOIN users u ON u.id = l.handed_out_by
              WHERE l.reservation_id = :rid' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute([':rid' => $reservationId]);
        $row = $stmt->fetch();
        if ($row === false) {
            return null;
        }
        $row['id'] = (int) $row['id'];
        return $row;
    }

    /** @return list<array<string,mixed>> */
    public function items(int $loanId, bool $forUpdate = false): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT li.id, li.equipment_id, e.name, li.quantity, li.returned_quantity
               FROM loan_items li JOIN equipment e ON e.id = li.equipment_id
              WHERE li.loan_id = :lid ORDER BY e.name, li.id' . ($forUpdate ? ' FOR UPDATE' : '')
        );
        $stmt->execute([':lid' => $loanId]);
        return array_map(static function (array $r): array {
            foreach (['id', 'equipment_id', 'quantity', 'returned_quantity'] as $k) {
                $r[$k] = (int) $r[$k];
            }
            $r['outstanding'] = $r['quantity'] - $r['returned_quantity'];
            return $r;
        }, $stmt->fetchAll());
    }

    /** 返却を1件記録する。返しすぎ（貸した数を超える）は条件付きUPDATEで止める */
    public function recordReturn(int $loanItemId, int $quantity, int $operatorId, ?string $note): bool
    {
        $upd = $this->pdo->prepare(
            'UPDATE loan_items SET returned_quantity = returned_quantity + :q1
              WHERE id = :id AND returned_quantity + :q2 <= quantity'
        );
        $upd->execute([':q1' => $quantity, ':q2' => $quantity, ':id' => $loanItemId]);
        if ($upd->rowCount() !== 1) {
            return false;
        }
        $ins = $this->pdo->prepare(
            'INSERT INTO returns (loan_item_id, quantity, returned_by, returned_at, condition_note)
             VALUES (:li, :q, :op, UTC_TIMESTAMP(), :note)'
        );
        $ins->execute([':li' => $loanItemId, ':q' => $quantity, ':op' => $operatorId, ':note' => $note]);
        return true;
    }

    public function markReturned(int $loanId): bool
    {
        $stmt = $this->pdo->prepare("UPDATE loans SET status = 'returned' WHERE id = :id AND status = 'on_loan'");
        $stmt->execute([':id' => $loanId]);
        return $stmt->rowCount() === 1;
    }

    /** @return list<array<string,mixed>> 返却の履歴 */
    public function returnsFor(int $loanId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT rt.quantity, rt.returned_at, rt.condition_note, e.name, u.name AS operator_name
               FROM returns rt
               JOIN loan_items li ON li.id = rt.loan_item_id
               JOIN equipment e ON e.id = li.equipment_id
               JOIN users u ON u.id = rt.returned_by
              WHERE li.loan_id = :lid ORDER BY rt.id'
        );
        $stmt->execute([':lid' => $loanId]);
        return $stmt->fetchAll();
    }

    /** 延滞中の貸出があるか（延滞中の人は新しく申請できない） */
    public function userHasOverdue(int $userId, string $today): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM loans l JOIN reservations r ON r.id = l.reservation_id
              WHERE r.user_id = :uid AND l.status = 'on_loan' AND l.due_date < :today LIMIT 1"
        );
        $stmt->execute([':uid' => $userId, ':today' => $today]);
        return $stmt->fetchColumn() !== false;
    }

    public function countOverdue(string $today): int
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM loans WHERE status = 'on_loan' AND due_date < :today");
        $stmt->execute([':today' => $today]);
        return (int) $stmt->fetchColumn();
    }
}

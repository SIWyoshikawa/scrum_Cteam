<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * equipment / equipment_categories（オーナー作成のテーブル）
 * 備品は「種類ごとに total_quantity 個ある」という持ち方。個体ごとの管理はしない。
 */
final class EquipmentRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array{id:int,name:string}> */
    public function categories(): array
    {
        $rows = $this->pdo->query('SELECT id, name FROM equipment_categories ORDER BY name')->fetchAll();
        return array_map(static fn (array $r) => ['id' => (int) $r['id'], 'name' => (string) $r['name']], $rows);
    }

    /**
     * 一覧・検索（REQ-F-004）。名前は部分一致、分類は完全一致。
     * $includeInactive … 利用停止中も出すか（管理者用）
     * @return list<array<string,mixed>>
     */
    public function search(string $keyword, ?int $categoryId, bool $includeInactive = false): array
    {
        $sql = 'SELECT e.id, e.name, e.total_quantity, e.description, e.storage_location, e.status,
                       c.name AS category_name
                  FROM equipment e
                  LEFT JOIN equipment_categories c ON c.id = e.category_id
                 WHERE 1 = 1';
        $params = [];
        if (!$includeInactive) {
            $sql .= " AND e.status = 'available'";
        }
        if ($keyword !== '') {
            // LIKE の特殊文字（% _ \）はエスケープしてから部分一致にする
            $sql .= " AND e.name LIKE :kw ESCAPE '\\\\'";
            $params[':kw'] = '%' . addcslashes($keyword, '%_\\') . '%';
        }
        if ($categoryId !== null) {
            $sql .= ' AND e.category_id = :cat';
            $params[':cat'] = $categoryId;
        }
        $sql .= ' ORDER BY e.name, e.id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return array_map(self::map(...), $stmt->fetchAll());
    }

    /** @param list<int> $ids @return array<int,array<string,mixed>> id => 行 */
    public function findMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        [$in, $params] = self::inList($ids, 'e');
        $stmt = $this->pdo->prepare("SELECT id, name, total_quantity, description, storage_location, status, NULL AS category_name
                                       FROM equipment WHERE id IN ({$in})");
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['id']] = self::map($row);
        }
        return $out;
    }

    /**
     * トランザクション内で備品行をロックする（同じ備品への承認・貸出が同時に走っても1件ずつ処理される）
     * デッドロックを避けるため、どの処理も「id の小さい順」にロックする。
     * @param list<int> $ids @return array<int,array<string,mixed>>
     */
    public function lockMany(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $ids = array_values(array_unique($ids));
        sort($ids);
        [$in, $params] = self::inList($ids, 'e');
        $stmt = $this->pdo->prepare("SELECT id, name, total_quantity, description, storage_location, status, NULL AS category_name
                                       FROM equipment WHERE id IN ({$in}) ORDER BY id FOR UPDATE");
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['id']] = self::map($row);
        }
        return $out;
    }

    /**
     * 期間 [start, end] に押さえられている数（備品ごと）
     * 押さえる状態：承認済み（approved）と、まだ返していない貸出（loans.on_loan の未返却分）
     * 申請中（pending）同士は重なってよい（REQ-F-009 第3問B案）ので数えない。
     * 未返却の貸出は返却予定日を過ぎても返るまで押さえ続ける（詳細設計 5.2）。
     *
     * @param list<int> $ids
     * @return array<int,int> id => 押さえられている数
     */
    public function reservedQuantities(array $ids, string $start, string $end, string $today, ?int $excludeReservationId = null): array
    {
        if ($ids === []) {
            return [];
        }
        [$in1, $p1] = self::inList($ids, 'a');
        [$in2, $p2] = self::inList($ids, 'b');
        $sql = "SELECT equipment_id, SUM(q) AS q FROM (
                    SELECT ri.equipment_id, ri.quantity AS q
                      FROM reservation_items ri
                      JOIN reservations r ON r.id = ri.reservation_id
                     WHERE r.status = 'approved'
                       AND r.start_date <= :end1 AND r.end_date >= :start1
                       AND r.id <> :ex1
                       AND ri.equipment_id IN ({$in1})
                    UNION ALL
                    SELECT li.equipment_id, (li.quantity - li.returned_quantity) AS q
                      FROM loan_items li
                      JOIN loans l ON l.id = li.loan_id
                      JOIN reservations r ON r.id = l.reservation_id
                     WHERE l.status = 'on_loan'
                       AND r.start_date <= :end2 AND GREATEST(r.end_date, :today) >= :start2
                       AND r.id <> :ex2
                       AND li.equipment_id IN ({$in2})
                ) t GROUP BY equipment_id";
        $stmt = $this->pdo->prepare($sql);
        $ex = $excludeReservationId ?? 0;
        $stmt->execute($p1 + $p2 + [
            ':end1' => $end, ':start1' => $start, ':ex1' => $ex,
            ':end2' => $end, ':start2' => $start, ':ex2' => $ex, ':today' => $today,
        ]);
        $out = array_fill_keys($ids, 0);
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['equipment_id']] = (int) $row['q'];
        }
        return $out;
    }

    /**
     * IN (...) のプレースホルダを作る。値は整数に変換済みのものだけを渡す（SQLに入力を連結しない）
     * @param list<int> $ids @return array{0:string,1:array<string,int>}
     */
    private static function inList(array $ids, string $prefix): array
    {
        $names = [];
        $params = [];
        foreach (array_values($ids) as $i => $id) {
            $names[] = ":{$prefix}{$i}";
            $params[":{$prefix}{$i}"] = (int) $id;
        }
        return [implode(',', $names), $params];
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private static function map(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['total_quantity'] = (int) $row['total_quantity'];
        return $row;
    }
}

<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * notifications（オーナー作成のテーブル）
 * 画面を開いたときに読む「アプリ内のお知らせ」。メール送信・リアルタイム通知はしない（要望書の対象外）。
 */
final class NotificationRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function add(int $userId, ?int $reservationId, string $type, string $message): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO notifications (user_id, reservation_id, type, message, is_read, created_at)
             VALUES (:uid, :rid, :type, :msg, FALSE, UTC_TIMESTAMP())'
        );
        $stmt->bindValue(':uid', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':rid', $reservationId, $reservationId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':type', $type);
        $stmt->bindValue(':msg', mb_substr($message, 0, 500));
        $stmt->execute();
    }

    /** @return list<array<string,mixed>> 本人の未読（新しい順） */
    public function unread(int $userId, int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, reservation_id, type, message, created_at FROM notifications
              WHERE user_id = :uid AND is_read = FALSE ORDER BY id DESC LIMIT ' . max(1, min($limit, 50))
        );
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll();
    }

    /** 本人のお知らせだけを既読にする */
    public function markAllRead(int $userId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE notifications SET is_read = TRUE, read_at = UTC_TIMESTAMP() WHERE user_id = :uid AND is_read = FALSE'
        );
        $stmt->execute([':uid' => $userId]);
    }
}

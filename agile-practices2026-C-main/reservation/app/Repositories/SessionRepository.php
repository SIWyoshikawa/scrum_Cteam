<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * user_sessions テーブル（DB設計書 DB-007〜011、5-3 / 5-4）
 * PHPのセッションIDそのものは保存せず、SHA-256 ハッシュだけを保存する。
 * 端末（ブラウザ）ごとに1行。複数端末ログイン可。
 */
final class SessionRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public static function hashToken(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }

    public function create(int $userId, string $tokenHash): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_sessions (user_id, session_token_hash, created_at, last_activity_at)
             VALUES (:uid, :hash, UTC_TIMESTAMP(6), UTC_TIMESTAMP(6))'
        );
        $stmt->execute([':uid' => $userId, ':hash' => $tokenHash]);
    }

    /**
     * セッション行の状態を調べる（DB設計書 5-4 の4条件）
     * @return 'valid'|'expired'|'invalid'
     *   valid   … 有効（行あり・未失効・ユーザーactive・30分以内に操作あり）
     *   expired … 30分無操作
     *   invalid … 行なし・失効済み・ユーザー停止・別ユーザーの行
     */
    public function check(string $tokenHash, int $userId, int $idleSeconds): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.user_id, s.revoked_at, u.status,
                    (s.last_activity_at > UTC_TIMESTAMP(6) - INTERVAL :idle SECOND) AS fresh
               FROM user_sessions s
               JOIN users u ON u.id = s.user_id
              WHERE s.session_token_hash = :hash'
        );
        $stmt->execute([':idle' => $idleSeconds, ':hash' => $tokenHash]);
        $row = $stmt->fetch();

        if ($row === false || (int) $row['user_id'] !== $userId || $row['revoked_at'] !== null || $row['status'] !== 'active') {
            return 'invalid';
        }
        return (int) $row['fresh'] === 1 ? 'valid' : 'expired';
    }

    /** 操作があったので最終操作時刻を更新 */
    public function touch(string $tokenHash): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE user_sessions SET last_activity_at = UTC_TIMESTAMP(6)
              WHERE session_token_hash = :hash AND revoked_at IS NULL'
        );
        $stmt->execute([':hash' => $tokenHash]);
    }

    /** この端末のセッションだけ失効（ログアウト・期限切れ） */
    public function revoke(string $tokenHash): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP(6)
              WHERE session_token_hash = :hash AND revoked_at IS NULL'
        );
        $stmt->execute([':hash' => $tokenHash]);
    }

    /** そのユーザーの全端末のセッションを失効（停止・仮PW再発行・PW変更） */
    public function revokeAllForUser(int $userId): int
    {
        $stmt = $this->pdo->prepare(
            'UPDATE user_sessions SET revoked_at = UTC_TIMESTAMP(6)
              WHERE user_id = :uid AND revoked_at IS NULL'
        );
        $stmt->execute([':uid' => $userId]);
        return $stmt->rowCount();
    }
}

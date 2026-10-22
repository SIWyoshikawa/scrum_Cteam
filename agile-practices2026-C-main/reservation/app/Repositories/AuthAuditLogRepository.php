<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * auth_audit_logs（DB設計書 DB-012）
 * INSERT だけを持つ。UPDATE / DELETE は作らない（DBユーザーにも権限を与えない）。
 * パスワードやセッショントークンは記録しない。
 */
final class AuthAuditLogRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function insert(?int $actorUserId, ?int $targetUserId, string $action, string $result, ?string $ipBinary): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO auth_audit_logs (actor_user_id, target_user_id, action, result, ip_address, occurred_at)
             VALUES (:actor, :target, :action, :result, :ip, UTC_TIMESTAMP(6))'
        );
        $stmt->bindValue(':actor', $actorUserId, $actorUserId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':target', $targetUserId, $targetUserId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':action', $action);
        $stmt->bindValue(':result', $result);
        $stmt->bindValue(':ip', $ipBinary, $ipBinary === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $stmt->execute();
    }
}

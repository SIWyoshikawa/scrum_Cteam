<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * 業務操作の監査ログ（詳細設計 6.7：操作者・日時・対象・変更前後・理由・相関ID）
 * 記録先はオーナー作成の audit_logs。INSERT だけ（DBユーザーにも UPDATE/DELETE 権限なし）。
 */
final class AuditLogger
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ?string $ip,
        private readonly string $correlationId,
    ) {
    }

    /** @param array<string,mixed> $details 変更前後・理由など。個人情報やパスワードは入れない */
    public function log(?int $actorId, string $action, string $entityType, ?int $entityId, array $details = []): void
    {
        $details += ['ip' => $this->ip, 'correlation_id' => $this->correlationId];
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, details, created_at)
             VALUES (:actor, :action, :type, :eid, :details, UTC_TIMESTAMP())'
        );
        $stmt->bindValue(':actor', $actorId, $actorId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':action', $action);
        $stmt->bindValue(':type', $entityType);
        $stmt->bindValue(':eid', $entityId, $entityId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':details', json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $stmt->execute();
    }
}

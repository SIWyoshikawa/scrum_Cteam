<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * audit_logs（オーナー作成のテーブル）への認証・アカウント操作の記録（DB設計書 DB-012）
 * INSERT だけを持つ。UPDATE / DELETE は作らない（DBユーザーにも権限を与えない）。
 * パスワードやセッショントークンは記録しない。
 *
 * audit_logs の列への入れ方：
 *   actor_user_id … 操作した人（CLI・未ログインなら NULL）
 *   action        … login_success など
 *   entity_type   … 'user'（対象がユーザー）
 *   entity_id     … 対象ユーザーの users.id
 *   details       … JSON {"result":"success|failure","ip":"192.0.2.1"}
 */
final class AuthAuditLogRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function insert(?int $actorUserId, ?int $targetUserId, string $action, string $result, ?string $ip): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO audit_logs (actor_user_id, action, entity_type, entity_id, details, created_at)
             VALUES (:actor, :action, \'user\', :target, :details, UTC_TIMESTAMP())'
        );
        $details = json_encode(['result' => $result, 'ip' => $ip], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $stmt->bindValue(':actor', $actorUserId, $actorUserId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':action', $action);
        $stmt->bindValue(':target', $targetUserId, $targetUserId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':details', $details);
        $stmt->execute();
    }
}

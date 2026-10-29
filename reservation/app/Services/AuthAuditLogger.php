<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\AuthAuditLogRepository;

/**
 * 認証・アカウント管理の監査ログ（DB設計書 DB-012）
 *
 * 記録先はオーナー作成の audit_logs。予約の承認・貸出・返却など業務操作も、
 * 業務画面を作る工程で同じ audit_logs に（entity_type = 'reservation' などで）記録する。
 */
final class AuthAuditLogger
{
    /** DB設計書 3章の action 例 ＋ ロック・ユーザー作成 */
    public const ACTIONS = [
        'login_success', 'login_failure', 'login_locked', 'logout',
        'password_changed', 'password_reset_by_admin',
        'account_suspended', 'account_reactivated', 'user_created',
    ];

    public function __construct(
        private readonly AuthAuditLogRepository $repo,
        private readonly ?string $ip, // 画面からの操作なら接続元IP、CLIなら null
    ) {
    }

    public function success(string $action, ?int $actorUserId, ?int $targetUserId): void
    {
        $this->write($action, 'success', $actorUserId, $targetUserId);
    }

    public function failure(string $action, ?int $actorUserId, ?int $targetUserId): void
    {
        $this->write($action, 'failure', $actorUserId, $targetUserId);
    }

    private function write(string $action, string $result, ?int $actorUserId, ?int $targetUserId): void
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException('未定義の監査アクション: ' . $action);
        }
        $ip = $this->ip !== null && filter_var($this->ip, FILTER_VALIDATE_IP) !== false ? $this->ip : null;
        $this->repo->insert($actorUserId, $targetUserId, $action, $result, $ip);
    }

    /** "192.168.0.1" / "::1" → VARBINARY(16) 用のバイナリ（PHP inet_pton） */
    public static function ipToBinary(?string $ip): ?string
    {
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $bin = inet_pton($ip);
        return $bin === false ? null : $bin;
    }
}

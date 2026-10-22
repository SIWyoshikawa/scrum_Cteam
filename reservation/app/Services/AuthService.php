<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\SessionRepository;
use App\Repositories\UserRepository;
use App\Support\Clock;
use App\Support\Database;
use App\Support\Session;

/**
 * ログイン・ログアウト・本人のパスワード変更（REQ-F-001 / DB設計書 5-2〜5-5）
 */
final class AuthService
{
    public const OK = 'ok';
    public const FAILED = 'failed';
    public const LOCKED = 'locked';

    private static ?string $dummyHash = null;

    public function __construct(
        private readonly Database $db,
        private readonly UserRepository $users,
        private readonly SessionRepository $sessions,
        private readonly LoginThrottle $throttle,
        private readonly AuthAuditLogger $audit,
        private readonly PasswordPolicy $policy,
        private readonly ?string $ip,
    ) {
    }

    /**
     * ログイン照合（DB設計書 5-2）
     * @return array{status:string, user:array<string,mixed>|null}
     */
    public function attempt(string $loginId, string $password): array
    {
        $now = Clock::nowUtc();
        $ipBin = AuthAuditLogger::ipToBinary($this->ip);

        if ($this->throttle->isLocked($loginId, $ipBin, $now)) {
            $target = $this->users->findByLoginId($loginId);
            $this->audit->failure('login_locked', null, $target['id'] ?? null);
            return ['status' => self::LOCKED, 'user' => null];
        }

        $user = $this->users->findByLoginId($loginId);

        // ユーザーがいない場合もダミーのハッシュで検証して、処理時間の差から存在を推測させない
        $hash = $user['password_hash'] ?? self::dummyHash();
        $passwordOk = password_verify($password, $hash);

        if ($user === null || !$passwordOk || !$user['is_active']) {
            $lockedNow = $this->throttle->recordFailure($loginId, $ipBin, $now);
            $this->audit->failure('login_failure', null, $user['id'] ?? null);
            if ($lockedNow) {
                $this->audit->failure('login_locked', null, $user['id'] ?? null);
            }
            return ['status' => $lockedNow ? self::LOCKED : self::FAILED, 'user' => null];
        }

        $this->throttle->recordSuccess($loginId, $ipBin, $now);
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $this->users->rehash($user['id'], password_hash($password, PASSWORD_DEFAULT));
        }
        $this->audit->success('login_success', $user['id'], $user['id']);
        return ['status' => self::OK, 'user' => $user];
    }

    /**
     * ログイン成功後：セッションIDを作り直し（固定攻撃対策）、新しいIDのハッシュを user_sessions に登録（5-3）
     */
    public function establishSession(int $userId): void
    {
        Session::regenerate();
        $this->sessions->create($userId, SessionRepository::hashToken(session_id()));
        $_SESSION['user_id'] = $userId;
    }

    /** ログアウト：この端末のセッションだけ失効（他の端末はそのまま） */
    public function logout(?int $userId): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->sessions->revoke(SessionRepository::hashToken(session_id()));
        }
        if ($userId !== null) {
            $this->audit->success('logout', $userId, $userId);
        }
        Session::destroy();
    }

    /**
     * 本人によるパスワード変更（DB設計書 5-5）
     * @param array<string,mixed> $user
     * @return list<string> エラー（空なら成功）
     */
    public function changePassword(array $user, string $current, string $new, string $confirm): array
    {
        $fresh = $this->users->findById($user['id']);
        if ($fresh === null || !password_verify($current, $fresh['password_hash'])) {
            $this->audit->failure('password_changed', $user['id'], $user['id']);
            return ['現在のパスワードが正しくありません。'];
        }

        $errors = $this->policy->validate($new, $user['login_id']);
        if ($new !== $confirm) {
            $errors[] = '新しいパスワード（確認）が一致しません。';
        }
        if ($new === $current) {
            $errors[] = '今と同じパスワードは使えません。';
        }
        if ($errors !== []) {
            return $errors;
        }

        // 先にセッションIDを作り直し、その新しいIDで行を登録し直す
        Session::regenerate();
        $newHash = SessionRepository::hashToken(session_id());

        $this->db->transaction(function () use ($user, $new, $newHash): void {
            $this->users->lockById($user['id']);
            // ハッシュ保存・変更必須の解除・変更日時を同一トランザクションで（5-5）
            $this->users->changePasswordBySelf($user['id'], password_hash($new, PASSWORD_DEFAULT));
            // 他の端末のセッションも失効させる（パスワードが漏れていた場合に備える）
            $this->sessions->revokeAllForUser($user['id']);
            $this->sessions->create($user['id'], $newHash);
            $this->audit->success('password_changed', $user['id'], $user['id']);
        });
        return [];
    }

    private static function dummyHash(): string
    {
        return self::$dummyHash ??= password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    }
}

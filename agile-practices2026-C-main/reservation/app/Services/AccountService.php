<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\SessionRepository;
use App\Repositories\UserRepository;
use App\Support\Database;
use App\Support\HttpException;

/**
 * 管理者によるアカウント操作（DB設計書 5-1 / 5-6 / 5-7）
 * いまは scripts/ のコマンドから使う。管理画面を作るときも同じメソッドを呼ぶ。
 * $actorId は操作した管理者の users.id（コマンドから実行した場合は null）。
 */
final class AccountService
{
    public function __construct(
        private readonly Database $db,
        private readonly UserRepository $users,
        private readonly SessionRepository $sessions,
        private readonly AuthAuditLogger $audit,
        private readonly PasswordPolicy $policy,
    ) {
    }

    /** 事前登録。仮パスワードを返す（ここでしか平文は分からない） */
    public function createUser(?int $actorId, string $loginId, string $email, string $role, ?string $tempPassword = null): array
    {
        $tempPassword ??= PasswordPolicy::generateTemporary();
        $errors = $this->policy->validate($tempPassword, $loginId);
        if ($errors !== []) {
            throw new \InvalidArgumentException(implode(' ', $errors));
        }
        $id = $this->db->transaction(function () use ($actorId, $loginId, $email, $role, $tempPassword): int {
            $id = $this->users->create($loginId, $email, password_hash($tempPassword, PASSWORD_DEFAULT), $role);
            $this->audit->success('user_created', $actorId, $id);
            return $id;
        });
        return ['id' => $id, 'temp_password' => $tempPassword];
    }

    /** 利用停止：状態変更と全セッション失効を同一トランザクションで（5-6） */
    public function suspend(?int $actorId, int $targetId): void
    {
        $this->guardSelf($actorId, $targetId);
        $this->db->transaction(function () use ($actorId, $targetId): void {
            $this->requireUser($targetId);
            if (!$this->users->setStatus($targetId, 'active', 'suspended')) {
                throw new HttpException(409, 'このアカウントは既に停止されています。');
            }
            $this->sessions->revokeAllForUser($targetId);
            $this->audit->success('account_suspended', $actorId, $targetId);
        });
    }

    /** 再有効化：古いセッションは復活させない（5-6） */
    public function reactivate(?int $actorId, int $targetId): void
    {
        $this->guardSelf($actorId, $targetId);
        $this->db->transaction(function () use ($actorId, $targetId): void {
            $this->requireUser($targetId);
            if (!$this->users->setStatus($targetId, 'suspended', 'active')) {
                throw new HttpException(409, 'このアカウントは停止されていません。');
            }
            $this->audit->success('account_reactivated', $actorId, $targetId);
        });
    }

    /** 仮パスワード再発行：ハッシュ更新・変更必須・全セッション失効・監査を同一トランザクションで（5-7） */
    public function resetPassword(?int $actorId, int $targetId): string
    {
        $temp = PasswordPolicy::generateTemporary();
        $this->db->transaction(function () use ($actorId, $targetId, $temp): void {
            $this->requireUser($targetId);
            $this->users->resetPasswordByAdmin($targetId, password_hash($temp, PASSWORD_DEFAULT));
            $this->sessions->revokeAllForUser($targetId);
            $this->audit->success('password_reset_by_admin', $actorId, $targetId);
        });
        return $temp; // 呼び出し側で1回だけ表示する。ログには残さない
    }

    private function requireUser(int $id): void
    {
        if ($this->users->lockById($id) === null) {
            throw new HttpException(404, 'ユーザーが見つかりません。');
        }
    }

    /** 管理者が自分自身を停止・解除して締め出されるのを防ぐ */
    private function guardSelf(?int $actorId, int $targetId): void
    {
        if ($actorId !== null && $actorId === $targetId) {
            throw new HttpException(409, '自分自身のアカウントは操作できません。');
        }
    }
}

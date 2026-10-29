<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * users / roles テーブル（オーナー作成 001_equipment_management.sql）
 * SQLはすべてプレースホルダでバインドする（DD 6.1）。
 *
 * 注意：users.user_id は「ログインID（文字列）」、users.id は「内部番号」。
 * アプリ内では取り違え防止のため、配列キーを次のように揃える：
 *   'id'       … users.id（数値。user_sessions.user_id などの外部キーが指すのはこちら）
 *   'login_id' … users.user_id（ログイン画面で入力する文字列）
 */
final class UserRepository
{
    public const ROLES = ['student', 'teacher', 'admin'];

    /** 画面に出すロール名（roles テーブルには表示名の列が無いので、ここで対応させる） */
    public const ROLE_LABELS = ['student' => '生徒', 'teacher' => '教員', 'admin' => '管理者'];

    private const SELECT = 'SELECT u.id, u.user_id AS login_id, u.name, u.email, u.password_hash, u.status,
                                   u.must_change_password, r.role_name AS role
                              FROM users u
                              JOIN roles r ON r.id = u.role_id';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array<string,mixed>|null */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE u.id = :id');
        $stmt->execute([':id' => $id]);
        return self::map($stmt->fetch());
    }

    /** @return array<string,mixed>|null */
    public function findByLoginId(string $loginId): ?array
    {
        $stmt = $this->pdo->prepare(self::SELECT . ' WHERE u.user_id = :login_id');
        $stmt->execute([':login_id' => $loginId]);
        return self::map($stmt->fetch());
    }

    public function emailExists(string $email): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM users WHERE email = :email');
        $stmt->execute([':email' => $email]);
        return $stmt->fetchColumn() !== false;
    }

    /** 管理者による事前登録（DB設計書 5-1）。仮パスワードなので must_change_password = TRUE */
    public function create(string $loginId, string $name, string $email, string $passwordHash, string $role): int
    {
        if (!in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException('不正なロール');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (user_id, name, password_hash, email, role_id, status, must_change_password)
             SELECT :login_id, :name, :hash, :email, r.id, \'active\', TRUE FROM roles r WHERE r.role_name = :role'
        );
        $stmt->execute([
            ':login_id' => $loginId, ':name' => $name, ':hash' => $passwordHash, ':email' => $email, ':role' => $role,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new \RuntimeException('roles にロールが登録されていません: ' . $role);
        }
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * 本人によるパスワード変更（DB設計書 5-5）
     * 変更日時は users に列が無いので、audit_logs の password_changed で記録する。
     */
    public function changePasswordBySelf(int $id, string $hash): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET password_hash = :hash, must_change_password = FALSE WHERE id = :id'
        );
        $stmt->execute([':hash' => $hash, ':id' => $id]);
    }

    /** 管理者による仮パスワード再発行（DB設計書 5-7） */
    public function resetPasswordByAdmin(int $id, string $hash): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE users SET password_hash = :hash, must_change_password = TRUE WHERE id = :id'
        );
        $stmt->execute([':hash' => $hash, ':id' => $id]);
    }

    /** ハッシュ方式が古い場合の自動更新（他の列は変えない） */
    public function rehash(int $id, string $hash): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
        $stmt->execute([':hash' => $hash, ':id' => $id]);
    }

    /**
     * 状態の変更（active / suspended）。行ロックで読み直してから条件付き更新する。
     * @return bool 実際に変わったら true
     */
    public function setStatus(int $id, string $from, string $to): bool
    {
        $stmt = $this->pdo->prepare('UPDATE users SET status = :to WHERE id = :id AND status = :from');
        $stmt->execute([':to' => $to, ':id' => $id, ':from' => $from]);
        return $stmt->rowCount() === 1;
    }

    /** トランザクション内で対象ユーザー行をロックする */
    public function lockById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id FROM users WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $id]);
        return $stmt->fetch() === false ? null : $this->findById($id);
    }

    /** @param array<string,mixed>|false $row @return array<string,mixed>|null */
    private static function map(array|false $row): ?array
    {
        if ($row === false) {
            return null;
        }
        $row['id'] = (int) $row['id'];
        $row['must_change_password'] = (bool) $row['must_change_password'];
        $row['is_active'] = $row['status'] === 'active';
        $row['role_display'] = self::ROLE_LABELS[$row['role']] ?? $row['role'];
        return $row;
    }
}

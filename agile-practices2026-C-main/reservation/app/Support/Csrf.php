<?php
declare(strict_types=1);

namespace App\Support;

/**
 * CSRF対策（DD 6.3）
 * セッションごとに推測困難なトークンを1つ発行し、状態を変えるPOSTで照合する。
 */
final class Csrf
{
    private const KEY = '_csrf_token';

    public static function token(): string
    {
        if (empty($_SESSION[self::KEY]) || !is_string($_SESSION[self::KEY])) {
            $_SESSION[self::KEY] = bin2hex(random_bytes(32));
        }
        return $_SESSION[self::KEY];
    }

    public static function validate(mixed $submitted): bool
    {
        $expected = $_SESSION[self::KEY] ?? null;
        if (!is_string($expected) || $expected === '' || !is_string($submitted)) {
            return false;
        }
        return hash_equals($expected, $submitted); // タイミング攻撃に強い比較
    }

    /** ログイン成功時などに作り直す */
    public static function rotate(): void
    {
        $_SESSION[self::KEY] = bin2hex(random_bytes(32));
    }
}

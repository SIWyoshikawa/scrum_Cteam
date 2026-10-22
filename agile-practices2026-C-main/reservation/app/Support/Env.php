<?php
declare(strict_types=1);

namespace App\Support;

/**
 * .env ファイルの簡易読み込み（KEY=VALUE 形式、# はコメント）
 * putenv() は使わず、このクラスの中だけに保持する。
 */
final class Env
{
    /** @var array<string,string> */
    private static array $values = [];

    public static function load(string $path): void
    {
        if (!is_file($path) || !is_readable($path)) {
            return;
        }
        self::$values = self::parse((string) file_get_contents($path));
    }

    /** @return array<string,string> */
    public static function parse(string $content): array
    {
        $values = [];
        foreach (preg_split('/\R/', $content) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $key)) {
                continue;
            }
            // 前後の引用符を外す
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                $value = substr($value, 1, -1);
            }
            $values[$key] = $value;
        }
        return $values;
    }

    public static function get(string $key, string $default = ''): string
    {
        return self::$values[$key] ?? $default;
    }

    public static function bool(string $key, bool $default): bool
    {
        if (!isset(self::$values[$key])) {
            return $default;
        }
        return in_array(strtolower(self::$values[$key]), ['1', 'true', 'on', 'yes'], true);
    }

    /** テスト用 */
    public static function set(array $values): void
    {
        self::$values = $values;
    }
}

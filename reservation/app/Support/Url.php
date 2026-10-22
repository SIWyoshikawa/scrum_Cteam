<?php
declare(strict_types=1);

namespace App\Support;

final class Url
{
    private static string $base = '';

    public static function setBase(string $base): void
    {
        self::$base = rtrim($base, '/');
    }

    /** "/login" → "/reservation/login"。"/" で始まるアプリ内パスのみ受け付ける。 */
    public static function to(string $path): string
    {
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            throw new \InvalidArgumentException('アプリ内パスは "/" で始めてください');
        }
        return self::$base . $path;
    }
}

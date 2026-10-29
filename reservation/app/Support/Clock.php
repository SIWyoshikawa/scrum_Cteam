<?php
declare(strict_types=1);

namespace App\Support;

/**
 * 時刻の扱いを1か所にまとめる（DB設計書 DB-015 / 5-10）
 *  - DBに保存・比較する時刻は UTC
 *  - 画面に出す時刻は Asia/Tokyo
 */
final class Clock
{
    public const DB_FORMAT = 'Y-m-d H:i:s.u'; // DATETIME(6) 用

    public static function nowUtc(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public static function toDb(\DateTimeImmutable $t): string
    {
        return $t->setTimezone(new \DateTimeZone('UTC'))->format(self::DB_FORMAT);
    }

    public static function fromDb(string $value): \DateTimeImmutable
    {
        return new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    /** 学内運用日（日本時間の今日）。予約の日付チェックや延滞判定の基準日 */
    public static function todayJst(?\DateTimeImmutable $now = null): string
    {
        return ($now ?? self::nowUtc())->setTimezone(new \DateTimeZone('Asia/Tokyo'))->format('Y-m-d');
    }

    /** DBのUTC文字列 → 画面用の日本時間 */
    public static function toJst(string $dbValue, string $format = 'Y-m-d H:i'): string
    {
        return self::fromDb($dbValue)->setTimezone(new \DateTimeZone('Asia/Tokyo'))->format($format);
    }
}

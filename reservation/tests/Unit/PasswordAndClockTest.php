<?php
declare(strict_types=1);

use App\Repositories\SessionRepository;
use App\Services\AuthAuditLogger;
use App\Services\PasswordPolicy;
use App\Support\Clock;

$policy = new PasswordPolicy(['min_length' => 12, 'max_bytes' => 72]);

return [
    'パスワード：12文字以上なら合格' => function () use ($policy): void {
        assert_same([], $policy->validate('correct-horse-battery', 's0001'));
    },
    'パスワード：短い・長すぎる・ユーザーIDを含む・同じ文字だけ は不合格' => function () use ($policy): void {
        assert_true($policy->validate('short', 's0001') !== [], '短い');
        assert_true($policy->validate(str_repeat('a', 73) . 'b', 's0001') !== [], '73バイト以上');
        assert_true($policy->validate('xxS0001yyyyyyyy', 's0001') !== [], 'ユーザーID（大文字小文字無視）');
        assert_true($policy->validate(str_repeat('z', 20), 's0001') !== [], '同じ文字だけ');
    },
    '仮パスワードは16文字で、毎回違い、ルールを満たす' => function () use ($policy): void {
        $a = PasswordPolicy::generateTemporary();
        $b = PasswordPolicy::generateTemporary();
        assert_same(16, strlen($a));
        assert_true($a !== $b);
        assert_same([], $policy->validate($a, 's0001'));
    },
    'セッションIDはSHA-256の16進64文字で保存（元のIDは残さない）' => function (): void {
        $h = SessionRepository::hashToken('abc');
        assert_same(64, strlen($h));
        assert_same(hash('sha256', 'abc'), $h);
    },
    'IPアドレスをVARBINARY(16)用に変換（IPv4=4バイト、IPv6=16バイト、不正値はnull）' => function (): void {
        assert_same(4, strlen((string) AuthAuditLogger::ipToBinary('192.168.0.1')));
        assert_same(16, strlen((string) AuthAuditLogger::ipToBinary('::1')));
        assert_same(null, AuthAuditLogger::ipToBinary('not-an-ip'));
        assert_same(null, AuthAuditLogger::ipToBinary(null));
    },
    '時刻：DBにはUTC、画面にはJST' => function (): void {
        $t = new DateTimeImmutable('2026-10-22 09:30:00', new DateTimeZone('Asia/Tokyo'));
        assert_same('2026-10-22 00:30:00.000000', Clock::toDb($t));
        assert_same('2026-10-22 09:30', Clock::toJst('2026-10-22 00:30:00.000000'));
        assert_same('2026-10-23', Clock::toJst('2026-10-22 15:00:00', 'Y-m-d'), 'UTC15時はJSTの翌日');
    },
];

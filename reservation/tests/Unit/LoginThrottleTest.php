<?php
declare(strict_types=1);

use App\Services\LoginThrottle;
use App\Tests\InMemoryLoginAttemptStore;

$config = ['window_seconds' => 900, 'max_failures' => 5, 'lock_seconds' => 900, 'ip_max_failures' => 20];
$t0 = new DateTimeImmutable('2026-10-22 01:00:00', new DateTimeZone('UTC'));
$at = static fn (int $sec): DateTimeImmutable => $t0->modify("+{$sec} seconds");
$ip1 = inet_pton('192.168.0.10');
$ip2 = inet_pton('192.168.0.20');

return [
    '4回失敗まではロックしない、5回目でロック' => function () use ($config, $at, $ip1): void {
        $th = new LoginThrottle(new InMemoryLoginAttemptStore(), $config);
        for ($i = 1; $i <= 4; $i++) {
            assert_same(false, $th->recordFailure('s0001', $ip1, $at($i * 10)), "{$i}回目");
        }
        assert_same(false, $th->isLocked('s0001', $ip1, $at(50)));
        assert_same(true, $th->recordFailure('s0001', $ip1, $at(60)), '5回目');
        assert_same(true, $th->isLocked('s0001', $ip1, $at(61)));
    },
    'ロックは5回目の失敗から15分（14分59秒はまだロック）' => function () use ($config, $at, $ip1): void {
        $th = new LoginThrottle(new InMemoryLoginAttemptStore(), $config);
        foreach ([0, 100, 200, 300, 400] as $s) {
            $th->recordFailure('s0001', $ip1, $at($s));
        }
        assert_same(true, $th->isLocked('s0001', $ip1, $at(400 + 899)));
        assert_same(false, $th->isLocked('s0001', $ip1, $at(400 + 900)));
    },
    'ロック明けの1回の失敗では再ロックしない' => function () use ($config, $at, $ip1): void {
        $th = new LoginThrottle(new InMemoryLoginAttemptStore(), $config);
        for ($i = 0; $i < 5; $i++) {
            $th->recordFailure('s0001', $ip1, $at(0));
        }
        assert_same(false, $th->recordFailure('s0001', $ip1, $at(901)));
    },
    '5回が15分以上に分散していればロックしない' => function () use ($config, $at, $ip1): void {
        $th = new LoginThrottle(new InMemoryLoginAttemptStore(), $config);
        foreach ([0, 300, 600, 850] as $s) {
            $th->recordFailure('s0001', $ip1, $at($s));
        }
        assert_same(false, $th->recordFailure('s0001', $ip1, $at(901)), '1回目から15分以上後の5回目');
    },
    'ログイン成功で失敗回数はリセット' => function () use ($config, $at, $ip1): void {
        $th = new LoginThrottle(new InMemoryLoginAttemptStore(), $config);
        for ($i = 0; $i < 4; $i++) {
            $th->recordFailure('s0001', $ip1, $at(0));
        }
        $th->recordSuccess('s0001', $ip1, $at(1));
        assert_same(false, $th->recordFailure('s0001', $ip1, $at(2)));
    },
    'ユーザーIDの大文字小文字は同じものとして数える' => function () use ($config, $at, $ip1): void {
        $th = new LoginThrottle(new InMemoryLoginAttemptStore(), $config);
        foreach (['S0001', 's0001', 'S0001', 's0001'] as $id) {
            $th->recordFailure($id, $ip1, $at(0));
        }
        assert_same(true, $th->recordFailure('s0001', $ip1, $at(1)));
    },
    'ユーザーIDごとに独立（他人の失敗で自分はロックされない）' => function () use ($config, $at, $ip1, $ip2): void {
        $th = new LoginThrottle(new InMemoryLoginAttemptStore(), $config);
        for ($i = 0; $i < 5; $i++) {
            $th->recordFailure('attacked', $ip1, $at(0));
        }
        assert_same(false, $th->isLocked('me', $ip2, $at(1)));
    },
    'IP補助：同一IPから20回失敗でIPごと一時拒否' => function () use ($config, $at, $ip1, $ip2): void {
        $th = new LoginThrottle(new InMemoryLoginAttemptStore(), $config);
        for ($i = 0; $i < 19; $i++) {
            $th->recordFailure('user' . $i, $ip1, $at(0)); // 毎回違うIDを狙う総当たり
        }
        assert_same(false, $th->isLocked('new-user', $ip1, $at(1)), '19回');
        $th->recordFailure('user19', $ip1, $at(1));
        assert_same(true, $th->isLocked('new-user', $ip1, $at(2)), '20回');
        assert_same(false, $th->isLocked('new-user', $ip2, $at(2)), '別IPは影響なし');
        assert_same(false, $th->isLocked('new-user', $ip1, $at(901)), '15分で解除');
    },
];

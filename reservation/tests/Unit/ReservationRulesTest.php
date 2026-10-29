<?php
declare(strict_types=1);

use App\Services\ReservationRules;

$rules = new ReservationRules(['max_days' => 7, 'max_advance_days' => 90, 'max_active' => 5, 'max_items' => 10, 'max_quantity' => 99]);
$today = '2026-11-02';
$ok = fn (string $s = '2026-11-05', string $e = '2026-11-06', string $p = '授業で使用', array $q = [2 => '1'], int $active = 0, bool $overdue = false)
    => $rules->validate($s, $e, $p, $q, $today, $active, $overdue);

return [
    '正しい申請は通り、数量0や空欄は除かれる' => function () use ($ok): void {
        $r = $ok(q: [1 => '0', 2 => '3', 3 => '', 4 => ' 1 ']);
        assert_same([], $r['errors']);
        assert_same([2 => 3, 4 => 1], $r['items']);
    },
    '今日の申請はできる・過去日は不可' => function () use ($ok): void {
        assert_same([], $ok('2026-11-02', '2026-11-02')['errors']);
        assert_true($ok('2026-11-01', '2026-11-02')['errors'] !== []);
    },
    '利用開始日は90日先まで' => function () use ($ok): void {
        assert_same([], $ok('2027-01-31', '2027-01-31')['errors']);      // 今日+90日
        assert_true($ok('2027-02-01', '2027-02-01')['errors'] !== []);  // 今日+91日
    },
    '期間は開始日・返却予定日を含めて7日まで' => function () use ($ok): void {
        assert_same([], $ok('2026-11-05', '2026-11-11')['errors']);      // 7日
        assert_true($ok('2026-11-05', '2026-11-12')['errors'] !== []);  // 8日
    },
    '返却予定日が開始日より前は不可' => function () use ($ok): void {
        assert_true($ok('2026-11-06', '2026-11-05')['errors'] !== []);
    },
    '存在しない日付や形式違いは不可' => function () use ($ok): void {
        foreach (['2026-02-30', '2026/11/05', '20261105', "2026-11-05'", ''] as $bad) {
            assert_true($ok($bad, '2026-11-06')['errors'] !== [], $bad);
        }
    },
    '利用目的は必須・500文字まで・制御文字は不可（改行は可）' => function () use ($ok): void {
        assert_true($ok(p: '   ')['errors'] !== []);
        assert_same([], $ok(p: str_repeat('あ', 500))['errors']);
        assert_true($ok(p: str_repeat('あ', 501))['errors'] !== []);
        assert_same([], $ok(p: "1行目\n2行目")['errors']);
        assert_true($ok(p: "a\x00b")['errors'] !== []);
    },
    '数量は1〜99の整数だけ' => function () use ($ok): void {
        foreach (['-1', '1.5', '100', 'abc', '1e2', '０１'] as $bad) {
            assert_true($ok(q: [2 => $bad])['errors'] !== [], $bad);
        }
        assert_true($ok(q: [])['errors'] !== [], '1つもない');
    },
    '進行中が5件以上なら申請不可' => function () use ($ok): void {
        assert_same([], $ok(active: 4)['errors']);
        assert_true($ok(active: 5)['errors'] !== []);
    },
    '延滞中なら申請不可' => function () use ($ok): void {
        assert_true($ok(overdue: true)['errors'] !== []);
    },
];

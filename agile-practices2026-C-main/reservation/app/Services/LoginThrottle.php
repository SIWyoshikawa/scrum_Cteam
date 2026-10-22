<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\LoginAttemptStore;

/**
 * ログイン試行制限（DD 6.6 / DB設計書 5-8）
 *  - ユーザーID単位：15分以内に5回失敗 → 5回目の失敗から15分ロック
 *  - IP単位（補助）：15分以内に20回失敗 → そのIPからは一時拒否
 * 存在しないユーザーIDにも同じルールを適用するので、ロックの有無から
 * 「そのIDが存在するか」は分からない。
 *
 * ロック中の試行は login_attempts に記録しない（記録するとロックが延々と延びて
 * 本人が使えなくなる＝サービス妨害になるため）。代わりに監査ログに login_locked を残す。
 */
final class LoginThrottle
{
    /** @param array{window_seconds:int, max_failures:int, lock_seconds:int, ip_max_failures:int} $config */
    public function __construct(
        private readonly LoginAttemptStore $store,
        private readonly array $config,
    ) {
    }

    public function isLocked(string $userIdInput, ?string $ipBinary, \DateTimeImmutable $now): bool
    {
        return $this->isAccountLocked($userIdInput, $now) || $this->isIpBlocked($ipBinary, $now);
    }

    public function isAccountLocked(string $userIdInput, \DateTimeImmutable $now): bool
    {
        $window = $this->config['window_seconds'];
        $lock = $this->config['lock_seconds'];
        $max = $this->config['max_failures'];

        // ロックの可能性がある範囲（「15分の窓」＋「15分のロック」）の失敗だけ取得
        $times = $this->store->failureTimesSinceLastSuccess(
            $userIdInput,
            $now->modify('-' . ($window + $lock) . ' seconds'),
        );
        $nowTs = $now->getTimestamp();
        $ts = array_map(static fn (\DateTimeImmutable $t) => $t->getTimestamp(), $times);

        // 連続する max 回の失敗が window 秒未満に収まり、その最後の失敗から lock 秒未満ならロック中
        for ($i = $max - 1, $n = count($ts); $i < $n; $i++) {
            if ($ts[$i] - $ts[$i - $max + 1] < $window && $nowTs - $ts[$i] < $lock) {
                return true;
            }
        }
        return false;
    }

    public function isIpBlocked(?string $ipBinary, \DateTimeImmutable $now): bool
    {
        $since = $now->modify('-' . $this->config['window_seconds'] . ' seconds');
        return $this->store->countIpFailuresSince($ipBinary, $since) >= $this->config['ip_max_failures'];
    }

    /** 失敗を記録。この失敗でロックが始まったら true */
    public function recordFailure(string $userIdInput, ?string $ipBinary, \DateTimeImmutable $now): bool
    {
        $this->store->record($userIdInput, $ipBinary, false, $now);
        return $this->isAccountLocked($userIdInput, $now);
    }

    public function recordSuccess(string $userIdInput, ?string $ipBinary, \DateTimeImmutable $now): void
    {
        $this->store->record($userIdInput, $ipBinary, true, $now);
    }
}

<?php
declare(strict_types=1);

namespace App\Repositories;

/**
 * ログイン試行記録の保存先（DB版とテスト用メモリ版を差し替えられるようにする）
 * DB設計書 DB-013：user_id_input（入力値そのまま）、ip_address（inet_pton）、succeeded
 */
interface LoginAttemptStore
{
    public function record(string $userIdInput, ?string $ipBinary, bool $succeeded, \DateTimeImmutable $at): void;

    /**
     * $since 以降の失敗時刻（古い順）。ただしそのIDで最後に成功した後のものだけ。
     * @return list<\DateTimeImmutable>
     */
    public function failureTimesSinceLastSuccess(string $userIdInput, \DateTimeImmutable $since): array;

    /** $since 以降の同一IPからの失敗回数 */
    public function countIpFailuresSince(?string $ipBinary, \DateTimeImmutable $since): int;
}

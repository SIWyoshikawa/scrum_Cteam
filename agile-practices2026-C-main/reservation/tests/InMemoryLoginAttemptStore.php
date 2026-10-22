<?php
declare(strict_types=1);

namespace App\Tests;

use App\Repositories\LoginAttemptStore;

/** テスト用：DBの代わりに配列に記録する（DBの _unicode_ci に合わせて大文字小文字は区別しない） */
final class InMemoryLoginAttemptStore implements LoginAttemptStore
{
    /** @var list<array{id:int, in:string, ip:?string, ok:bool, at:\DateTimeImmutable}> */
    public array $rows = [];

    public function record(string $userIdInput, ?string $ipBinary, bool $succeeded, \DateTimeImmutable $at): void
    {
        $this->rows[] = ['id' => count($this->rows) + 1, 'in' => mb_strtolower($userIdInput), 'ip' => $ipBinary, 'ok' => $succeeded, 'at' => $at];
    }

    public function failureTimesSinceLastSuccess(string $userIdInput, \DateTimeImmutable $since): array
    {
        $in = mb_strtolower($userIdInput);
        $lastOk = 0;
        foreach ($this->rows as $r) {
            if ($r['in'] === $in && $r['ok']) {
                $lastOk = $r['id'];
            }
        }
        $times = [];
        foreach ($this->rows as $r) {
            if ($r['in'] === $in && !$r['ok'] && $r['at'] >= $since && $r['id'] > $lastOk) {
                $times[] = $r['at'];
            }
        }
        return $times;
    }

    public function countIpFailuresSince(?string $ipBinary, \DateTimeImmutable $since): int
    {
        if ($ipBinary === null) {
            return 0;
        }
        $n = 0;
        foreach ($this->rows as $r) {
            if ($r['ip'] === $ipBinary && !$r['ok'] && $r['at'] >= $since) {
                $n++;
            }
        }
        return $n;
    }
}

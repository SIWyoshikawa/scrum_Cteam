<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Support\Clock;
use PDO;

final class LoginAttemptRepository implements LoginAttemptStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function record(string $userIdInput, ?string $ipBinary, bool $succeeded, \DateTimeImmutable $at): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO login_attempts (user_id_input, ip_address, succeeded, attempted_at)
             VALUES (:input, :ip, :ok, :at)'
        );
        $stmt->bindValue(':input', $userIdInput);
        $stmt->bindValue(':ip', $ipBinary, $ipBinary === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
        $stmt->bindValue(':ok', $succeeded ? 1 : 0, PDO::PARAM_INT);
        $stmt->bindValue(':at', Clock::toDb($at));
        $stmt->execute();
    }

    public function failureTimesSinceLastSuccess(string $userIdInput, \DateTimeImmutable $since): array
    {
        // 同じ時刻でも順序が崩れないよう、「最後の成功」は id で比較する
        $stmt = $this->pdo->prepare(
            'SELECT attempted_at FROM login_attempts
              WHERE user_id_input = :in1
                AND succeeded = FALSE
                AND attempted_at >= :since
                AND id > COALESCE((SELECT MAX(id) FROM login_attempts
                                    WHERE user_id_input = :in2 AND succeeded = TRUE), 0)
              ORDER BY id'
        );
        $stmt->execute([':in1' => $userIdInput, ':in2' => $userIdInput, ':since' => Clock::toDb($since)]);
        return array_map(static fn (string $v) => Clock::fromDb($v), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function countIpFailuresSince(?string $ipBinary, \DateTimeImmutable $since): int
    {
        if ($ipBinary === null) {
            return 0;
        }
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
              WHERE ip_address = :ip AND succeeded = FALSE AND attempted_at >= :since'
        );
        $stmt->bindValue(':ip', $ipBinary, PDO::PARAM_LOB);
        $stmt->bindValue(':since', Clock::toDb($since));
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }
}

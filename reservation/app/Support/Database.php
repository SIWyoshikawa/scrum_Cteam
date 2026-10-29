<?php
declare(strict_types=1);

namespace App\Support;

use PDO;

/**
 * DB接続（DD 6.1）
 * - 例外モード（エラーを見逃さない）
 * - 本物のプリペアドステートメント（エミュレーション無効）
 * - utf8mb4 / 接続のタイムゾーンはUTC（DB設計書 5-10：保存はUTC、表示はJST）
 * SQLに外部入力を「文字列連結」してはいけない。必ず :name のバインドを使う。
 */
final class Database
{
    private ?PDO $pdo = null;

    /** @param array{host:string, port:int, name:string, user:string, password:string} $config */
    public function __construct(private readonly array $config)
    {
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $c = $this->config;
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], $c['port'], $c['name']);
            $this->pdo = new PDO($dsn, $c['user'], $c['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
            $this->pdo->exec("SET time_zone = '+00:00'");
            $this->pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
            // 各SQLが「その時点で確定している最新の行」を読むようにする。
            // 既定の REPEATABLE READ だと、行ロックを待っている間に他の人が確定した承認が
            // 見えず（古いスナップショットのまま）、在庫を二重に押さえてしまう（詳細設計 5.3）。
            $this->pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
        }
        return $this->pdo;
    }

    /**
     * トランザクション実行。例外が出たら必ずロールバックして例外を投げ直す（DD 2.3 TransactionManager）
     * @template T
     * @param callable(PDO):T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn($pdo);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

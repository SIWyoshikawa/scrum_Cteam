<?php
declare(strict_types=1);

namespace App\Support;

/**
 * サーバ側ログ（storage/logs、Web非公開）
 * 1行1件のJSON。パスワードやトークンは絶対に渡さないこと。
 */
final class Logger
{
    private string $correlationId;

    public function __construct(private readonly string $dir)
    {
        // リクエストごとの参照ID。画面のエラー表示とログを結びつける（DD 2.3 ErrorHandler）
        $this->correlationId = bin2hex(random_bytes(8));
    }

    public function correlationId(): string
    {
        return $this->correlationId;
    }

    /** @param array<string,mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    /** @param array<string,mixed> $context */
    private function write(string $level, string $message, array $context): void
    {
        $line = json_encode([
            'time'           => date('c'),
            'level'          => $level,
            'correlation_id' => $this->correlationId,
            'message'        => $message,
            'context'        => $context,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0750, true);
        }
        $file = $this->dir . '/app-' . date('Y-m-d') . '.log';
        if (@file_put_contents($file, $line . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            error_log($line); // 書けない場合はPHP標準のエラーログへ
        }
    }
}

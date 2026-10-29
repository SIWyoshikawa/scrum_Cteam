<?php
declare(strict_types=1);

namespace App\Support;

final class Request
{
    /** @param array<string,mixed> $query @param array<string,mixed> $post @param array<string,mixed> $server */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        private readonly array $query,
        private readonly array $post,
        private readonly array $server,
    ) {
    }

    public static function fromGlobals(string $basePath): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
        $path = rawurldecode($path);

        // ベースパス（例: /reservation）と、.htaccess の書き換え先 /public を取り除く
        if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
            $path = substr($path, strlen($basePath));
        }
        if ($path === '/public' || str_starts_with($path, '/public/')) {
            $path = substr($path, strlen('/public'));
        }
        if ($path === '/index.php') {
            $path = '/';
        }
        $path = '/' . trim($path, '/');

        return new self($method, $path, $_GET, $_POST, $_SERVER);
    }

    /** POSTの文字列値。配列などが送られてきた場合は null（型の改ざん対策） */
    public function input(string $key): ?string
    {
        $value = $this->post[$key] ?? null;
        return is_string($value) ? $value : null;
    }

    /**
     * POSTの配列値（例：qty[3]=2）。キーは正の整数、値は文字列だけ残す（それ以外は捨てる）
     * @return array<int,string>
     */
    public function inputMap(string $key, int $maxItems = 100): array
    {
        $value = $this->post[$key] ?? null;
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $k => $v) {
            if (is_string($v) && preg_match('/^[1-9][0-9]{0,9}$/', (string) $k)) {
                $out[(int) $k] = $v;
            }
            if (count($out) >= $maxItems) {
                break;
            }
        }
        return $out;
    }

    public function query(string $key): ?string
    {
        $value = $this->query[$key] ?? null;
        return is_string($value) ? $value : null;
    }

    /** X-Forwarded-For は偽装できるので使わない */
    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function isHttps(): bool
    {
        $https = strtolower((string) ($this->server['HTTPS'] ?? ''));
        return ($https !== '' && $https !== 'off') || (string) ($this->server['SERVER_PORT'] ?? '') === '443';
    }

    public function isFromLocalhost(): bool
    {
        return in_array($this->ip(), ['127.0.0.1', '::1'], true);
    }

    public function isSafeMethod(): bool
    {
        return in_array($this->method, ['GET', 'HEAD'], true);
    }
}

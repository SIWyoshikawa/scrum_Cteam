<?php
declare(strict_types=1);

namespace App\Support;

/**
 * 明示ルート方式のルーター（DD 2.1 / 3）
 * 登録したメソッド＋パスだけを受け付け、それ以外は 404 / 405 にする。
 */
final class Router
{
    /** @var list<array{method:string, regex:string, handler:array{0:class-string,1:string}, middleware:list<string>}> */
    private array $routes = [];

    /**
     * @param array{0:class-string,1:string} $handler
     * @param list<string> $middleware 例: ['auth', 'role:admin']
     */
    public function add(string $method, string $pattern, array $handler, array $middleware = []): void
    {
        $this->routes[] = [
            'method'     => strtoupper($method),
            'regex'      => self::compile($pattern),
            'handler'    => $handler,
            'middleware' => $middleware,
        ];
    }

    public function get(string $pattern, array $handler, array $middleware = []): void
    {
        $this->add('GET', $pattern, $handler, $middleware);
    }

    public function post(string $pattern, array $handler, array $middleware = []): void
    {
        $this->add('POST', $pattern, $handler, $middleware);
    }

    /**
     * @return array{handler:array{0:class-string,1:string}, middleware:list<string>, params:array<string,int>}
     * @throws HttpException 404 / 405
     */
    public function match(string $method, string $path): array
    {
        $method = $method === 'HEAD' ? 'GET' : $method;
        $allowed = [];

        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $path, $m)) {
                continue;
            }
            if ($route['method'] !== $method) {
                $allowed[] = $route['method'];
                continue;
            }
            $params = [];
            foreach ($m as $key => $value) {
                if (is_string($key)) {
                    $params[$key] = (int) $value;
                }
            }
            return ['handler' => $route['handler'], 'middleware' => $route['middleware'], 'params' => $params];
        }

        if ($allowed !== []) {
            // 例：GET /logout は 405（GETで状態を変えない DD 2.3）
            throw new HttpException(405, '', ['Allow' => implode(', ', array_unique($allowed))]);
        }
        throw new HttpException(404);
    }

    /** "/items/{id}" → 正規表現。{名前} は 1 以上の整数IDだけに一致させる（不正IDを入口で弾く） */
    private static function compile(string $pattern): string
    {
        $parts = preg_split('/(\{[a-z_]+\})/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
        $regex = '';
        foreach ($parts as $part) {
            if (preg_match('/^\{([a-z_]+)\}$/', $part, $m)) {
                $regex .= '(?P<' . $m[1] . '>[1-9][0-9]{0,17})';
            } else {
                $regex .= preg_quote($part, '#');
            }
        }
        return '#^' . $regex . '$#';
    }
}

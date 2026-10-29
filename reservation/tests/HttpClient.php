<?php
declare(strict_types=1);

/**
 * テスト用の簡易ブラウザ（Cookie を覚えて、CSRFトークン付きでPOSTできる）
 * tests/Security/smoke_test.php と tests/Feature/*.php から使う。
 */
final class Client
{
    /** @var array<string,string> */
    public array $cookies = [];

    public function __construct(private readonly string $base)
    {
    }

    /** @return array{status:int, headers:array<string,string>, body:string} */
    public function request(string $method, string $path, array $form = []): array
    {
        $ch = curl_init($this->base . $path);
        $headers = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$headers): int {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $k = strtolower(trim($k));
                    $v = trim($v);
                    if ($k === 'set-cookie') {
                        [$pair] = explode(';', $v, 2);
                        [$ck, $cv] = explode('=', $pair, 2);
                        if ($cv === '' || $cv === 'deleted' || preg_match('/max-age=0/i', $v)) {
                            unset($this->cookies[$ck]);
                        } else {
                            $this->cookies[$ck] = $cv;
                        }
                        $headers['set-cookie'] = ($headers['set-cookie'] ?? '') . $v . "\n";
                    } else {
                        $headers[$k] = $v;
                    }
                }
                return strlen($line);
            },
        ]);
        if ($this->cookies !== []) {
            curl_setopt($ch, CURLOPT_COOKIE, http_build_query($this->cookies, '', '; '));
        }
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
        }
        $body = (string) curl_exec($ch);
        return ['status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'headers' => $headers, 'body' => $body];
    }

    public static function csrfFrom(string $html): string
    {
        return preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $html, $m) ? $m[1] : '';
    }

    public function login(string $id, string $pass): array
    {
        $page = $this->request('GET', '/login');
        return $this->request('POST', '/login', ['_csrf' => self::csrfFrom($page['body']), 'login_id' => $id, 'password' => $pass]);
    }

    /** ログイン中の画面からトークンを取ってPOST */
    public function postFrom(string $pagePath, string $path, array $form): array
    {
        $page = $this->request('GET', $pagePath);
        return $this->request('POST', $path, ['_csrf' => self::csrfFrom($page['body'])] + $form);
    }

    public function changePassword(string $current, string $new, ?string $confirm = null): array
    {
        return $this->postFrom('/password/change', '/password/change', [
            'current_password' => $current, 'new_password' => $new, 'new_password_confirmation' => $confirm ?? $new,
        ]);
    }

    public function sid(): string
    {
        return $this->cookies['RSVSESSID'] ?? '';
    }
}

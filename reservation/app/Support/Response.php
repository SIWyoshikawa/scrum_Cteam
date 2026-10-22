<?php
declare(strict_types=1);

namespace App\Support;

final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = [],
    ) {
    }

    /** アプリ内へのリダイレクトのみ（外部URLへ飛ばす「オープンリダイレクト」を防ぐ） */
    public static function redirect(string $appPath, int $status = 303): self
    {
        return new self('', $status, ['Location' => Url::to($appPath)]);
    }

    public function send(bool $isHttps): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach (self::securityHeaders($isHttps) + $this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }
        echo $this->body;
    }

    /** @return array<string,string> */
    public static function securityHeaders(bool $isHttps): array
    {
        $headers = [
            'Content-Type'            => 'text/html; charset=UTF-8',
            'X-Content-Type-Options'  => 'nosniff',
            'X-Frame-Options'         => 'DENY',
            'Referrer-Policy'         => 'same-origin',
            'Permissions-Policy'      => 'camera=(), microphone=(), geolocation=()',
            // 自サイトのファイル以外のスクリプト・スタイルを読み込ませない（XSSの被害を減らす）
            'Content-Security-Policy' => "default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data:; "
                . "form-action 'self'; frame-ancestors 'none'; base-uri 'self'; object-src 'none'",
            // ログイン後の画面をブラウザや共有PCに残さない
            'Cache-Control'           => 'no-store',
            'Pragma'                  => 'no-cache',
        ];
        if ($isHttps) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000';
        }
        return $headers;
    }
}

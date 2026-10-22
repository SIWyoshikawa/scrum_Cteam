<?php
declare(strict_types=1);

namespace App\Services;

/**
 * 新しいパスワードのルール（初回変更・本人変更・仮パスワード発行で共通）
 */
final class PasswordPolicy
{
    /** @param array{min_length:int, max_bytes:int} $config */
    public function __construct(private readonly array $config)
    {
    }

    /** @return list<string> エラーメッセージ（空なら合格） */
    public function validate(string $new, string $loginId): array
    {
        $errors = [];
        if (mb_strlen($new) < $this->config['min_length']) {
            $errors[] = "パスワードは{$this->config['min_length']}文字以上にしてください。";
        }
        if (strlen($new) > $this->config['max_bytes']) {
            // bcrypt は72バイトを超える部分を無視するため
            $errors[] = 'パスワードが長すぎます（半角72文字まで、全角は24文字程度まで）。';
        }
        if ($loginId !== '' && mb_stripos($new, $loginId) !== false) {
            $errors[] = 'パスワードにユーザーIDを含めないでください。';
        }
        if (preg_match('/^(.)\1+$/u', $new)) {
            $errors[] = '同じ文字だけのパスワードは使えません。';
        }
        return $errors;
    }

    /** 仮パスワード：紛らわしい文字（0/O, 1/l/I）を除いた16文字 */
    public static function generateTemporary(): string
    {
        $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';
        $password = '';
        for ($i = 0; $i < 16; $i++) {
            $password .= $chars[random_int(0, strlen($chars) - 1)];
        }
        return $password;
    }
}

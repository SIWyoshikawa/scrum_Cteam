<?php
declare(strict_types=1);

namespace App\Support;

/**
 * 利用者に見せてよいエラー（401/403/404/405/409/422/429 など）
 * message は画面にそのまま出るので、内部情報を入れないこと。
 */
final class HttpException extends \RuntimeException
{
    /** @param array<string,string> $headers */
    public function __construct(
        public readonly int $status,
        string $publicMessage = '',
        public readonly array $headers = [],
    ) {
        parent::__construct($publicMessage !== '' ? $publicMessage : self::defaultMessage($status), $status);
    }

    public static function defaultMessage(int $status): string
    {
        return match ($status) {
            400 => 'リクエストの内容が正しくありません。',
            401 => 'ログインが必要です。',
            403 => 'この操作を行う権限がありません。',
            404 => 'ページが見つかりません。',
            405 => 'この方法では操作できません。',
            409 => '他の操作と競合したため処理できませんでした。画面を開き直してください。',
            422 => '入力内容を確認してください。',
            429 => '操作回数が多すぎます。しばらく待ってから再度お試しください。',
            default => '処理を完了できませんでした。',
        };
    }
}

<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\App;
use App\Support\HttpException;
use App\Support\Response;
use App\Support\Session;

abstract class Controller
{
    public function __construct(protected readonly App $app)
    {
    }

    /**
     * 状態を変える操作の共通処理：成功したら $okMessage を出して $back へ、
     * 利用者に見せてよいエラー（409/422など）ならその文言を出して $back へ戻す（PRGパターン）
     * @param callable():(string|null) $action 戻り値が文字列ならそれを成功メッセージにする
     */
    protected function perform(string $back, string $okMessage, callable $action): Response
    {
        try {
            $message = $action();
            Session::flash('info', is_string($message) ? $message : $okMessage);
        } catch (HttpException $e) {
            if ($e->status >= 500 || $e->status === 404) {
                throw $e;
            }
            Session::flash('error', $e->getMessage());
        }
        return Response::redirect($back);
    }

    /** ログイン中ユーザーの users.id（数値） */
    protected function userId(): int
    {
        return (int) $this->app->user['id'];
    }

    /** @param array<string,mixed> $data */
    protected function view(string $template, array $data = [], int $status = 200): Response
    {
        return new Response($this->app->view->render($template, $data), $status);
    }
}

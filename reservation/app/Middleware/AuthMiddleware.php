<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Support\App;
use App\Support\HttpException;
use App\Support\Request;
use App\Support\Response;

/**
 * ログイン必須（未ログイン：GETはログイン画面へ、POSTは401）
 *
 * 初回・仮パスワード再発行後（must_change_password = TRUE）は、
 * パスワード変更とログアウト以外を使わせない（DB設計書 5-5）。
 * それらのルートには 'auth:allow_password_change' を付ける。
 */
final class AuthMiddleware implements Middleware
{
    public function handle(App $app, Request $request, ?string $arg): ?Response
    {
        if ($app->user === null) {
            if ($request->isSafeMethod()) {
                return Response::redirect('/login');
            }
            throw new HttpException(401);
        }

        if ($app->user['must_change_password'] && $arg !== 'allow_password_change') {
            if ($request->isSafeMethod()) {
                return Response::redirect('/password/change');
            }
            throw new HttpException(403, '先にパスワードを変更してください。');
        }
        return null;
    }
}

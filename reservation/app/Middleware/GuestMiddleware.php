<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Support\App;
use App\Support\Request;
use App\Support\Response;

/** 未ログイン専用（ログイン済みでログイン画面に来たらホームへ） */
final class GuestMiddleware implements Middleware
{
    public function handle(App $app, Request $request, ?string $arg): ?Response
    {
        return $app->user === null ? null : Response::redirect('/');
    }
}

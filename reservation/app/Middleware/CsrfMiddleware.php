<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Support\App;
use App\Support\Csrf;
use App\Support\HttpException;
use App\Support\Request;
use App\Support\Response;

final class CsrfMiddleware implements Middleware
{
    public function handle(App $app, Request $request, ?string $arg): ?Response
    {
        if (!Csrf::validate($request->input('_csrf'))) {
            $app->logger->warning('csrf_rejected', ['path' => $request->path, 'method' => $request->method]);
            throw new HttpException(403, '画面の有効期限が切れたか、正しくない操作です。画面を開き直してからやり直してください。');
        }
        return null;
    }
}

<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Support\App;
use App\Support\Request;
use App\Support\Response;

interface Middleware
{
    /** 通してよければ null、止めるなら Response を返す（または HttpException を投げる） */
    public function handle(App $app, Request $request, ?string $arg): ?Response;
}

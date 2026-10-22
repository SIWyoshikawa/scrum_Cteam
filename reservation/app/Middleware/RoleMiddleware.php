<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Support\App;
use App\Support\HttpException;
use App\Support\Request;
use App\Support\Response;

/**
 * ロール確認（DD 6.4）
 * ボタンを隠すだけでなく、URLを直接打たれてもここで止める。
 * ロールはセッションの値ではなく、毎回DBから読み直したユーザー情報で判定する。
 *
 *   role:admin … 管理者のみ
 *   role:user  … 一般利用者（student と teacher）。
 *                teacher は PO決定により一般利用者と同じ扱い（DB設計書 5-11）
 */
final class RoleMiddleware implements Middleware
{
    private const GROUPS = [
        'admin' => ['admin'],
        'user'  => ['student', 'teacher'],
    ];

    public function handle(App $app, Request $request, ?string $arg): ?Response
    {
        $allowed = self::GROUPS[$arg ?? ''] ?? throw new \LogicException('ロール指定が不正です: ' . $arg);
        if (!in_array($app->user['role'] ?? null, $allowed, true)) {
            $app->logger->warning('forbidden', [
                'user_id' => $app->user['id'] ?? null,
                'path'    => $request->path,
                'need'    => $arg,
            ]);
            throw new HttpException(403);
        }
        return null;
    }
}

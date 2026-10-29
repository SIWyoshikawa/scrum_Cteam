<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\Request;
use App\Support\Response;

final class HomeController extends Controller
{
    /** ロールごとのホーム画面（テンプレート名, 画面タイトル） */
    private const HOMES = [
        'student' => ['home/student', '生徒ホーム'],
        'teacher' => ['home/teacher', '教員ホーム'],
    ];

    /**
     * GET /（ログイン後のトップ）
     * 生徒・教員で画面を分ける。権限は同じ（DB設計書 5-11）で、表示する案内だけが違う。
     * 管理者は管理メニューへ送る。
     */
    public function index(Request $request, array $params): Response
    {
        $role = (string) ($this->app->user['role'] ?? '');
        if ($role === 'admin') {
            return Response::redirect('/admin');
        }
        [$template, $title] = self::HOMES[$role] ?? ['home/index', 'ホーム'];
        return $this->view($template, ['title' => $title]);
    }
}

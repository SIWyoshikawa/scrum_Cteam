<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Support\Request;
use App\Support\Response;

final class AuthController extends Controller
{
    private const GENERIC_FAIL = 'ユーザーIDまたはパスワードが正しくありません。';
    private const LOCKED = 'ログインの失敗が続いたため、一時的にログインできません。15分ほど待ってから再度お試しください。';

    /** GET /login */
    public function showLogin(Request $request, array $params): Response
    {
        $notice = $request->query('logged_out') === '1' ? 'ログアウトしました。' : null;
        return $this->view('auth/login', ['title' => 'ログイン', 'loginId' => '', 'error' => null, 'notice' => $notice]);
    }

    /** POST /login */
    public function login(Request $request, array $params): Response
    {
        $loginId = trim((string) $request->input('login_id'));
        $password = (string) $request->input('password');

        // 入力チェック（users.user_id / login_attempts.user_id_input は VARCHAR(50)）
        if ($loginId === '' || $password === '' || mb_strlen($loginId) > 50 || strlen($password) > 1024) {
            return $this->view('auth/login', [
                'title' => 'ログイン', 'loginId' => mb_substr($loginId, 0, 50), 'notice' => null,
                'error' => 'ユーザーID（50文字以内）とパスワードを入力してください。',
            ], 422);
        }

        $auth = $this->app->auth();
        $result = $auth->attempt($loginId, $password);

        if ($result['status'] !== AuthService::OK) {
            $locked = $result['status'] === AuthService::LOCKED;
            return $this->view('auth/login', [
                'title' => 'ログイン', 'loginId' => $loginId, 'notice' => null,
                'error' => $locked ? self::LOCKED : self::GENERIC_FAIL,
            ], $locked ? 429 : 401);
        }

        $user = $result['user'];
        $auth->establishSession($user['id']);

        if ($user['must_change_password']) {
            return Response::redirect('/password/change');
        }
        return Response::redirect($user['role'] === 'admin' ? '/admin' : '/');
    }

    /** POST /logout（GETでは受け付けない） */
    public function logout(Request $request, array $params): Response
    {
        $this->app->auth()->logout($this->app->user['id'] ?? null);
        return Response::redirect('/login?logged_out=1');
    }
}

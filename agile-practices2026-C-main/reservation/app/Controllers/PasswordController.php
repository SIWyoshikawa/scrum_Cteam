<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\Request;
use App\Support\Response;
use App\Support\Session;

/** パスワード変更（初回・仮パスワード再発行後は必須。DB設計書 5-5） */
final class PasswordController extends Controller
{
    /** GET /password/change */
    public function show(Request $request, array $params): Response
    {
        return $this->view('auth/password_change', ['title' => 'パスワード変更', 'errors' => []]);
    }

    /** POST /password/change */
    public function update(Request $request, array $params): Response
    {
        $current = (string) $request->input('current_password');
        $new = (string) $request->input('new_password');
        $confirm = (string) $request->input('new_password_confirmation');

        if ($current === '' || $new === '' || strlen($current) > 1024 || strlen($new) > 1024) {
            return $this->view('auth/password_change', [
                'title' => 'パスワード変更', 'errors' => ['すべての欄を入力してください。'],
            ], 422);
        }

        $errors = $this->app->auth()->changePassword($this->app->user, $current, $new, $confirm);
        if ($errors !== []) {
            return $this->view('auth/password_change', ['title' => 'パスワード変更', 'errors' => $errors], 422);
        }

        Session::flash('info', 'パスワードを変更しました。他の端末でのログインはすべて解除されました。');
        return Response::redirect($this->app->user['role'] === 'admin' ? '/admin' : '/');
    }
}

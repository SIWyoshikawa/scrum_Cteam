<?php
declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\HomeController;
use App\Controllers\PasswordController;
use App\Support\Router;

/**
 * ルート一覧（明示登録したものだけが動く）
 * ミドルウェア:
 *   guest                       … 未ログインのみ（ログイン済みならホームへ）
 *   auth                        … ログイン必須＋パスワード変更済み
 *   auth:allow_password_change  … ログイン必須（パスワード変更前でも可）
 *   role:admin                  … 管理者のみ
 *   role:user                   … 一般利用者（生徒・教員）のみ
 * POST はすべて自動でCSRF照合される。
 */
$router = new Router();

$router->get('/login', [AuthController::class, 'showLogin'], ['guest']);
$router->post('/login', [AuthController::class, 'login'], ['guest']);
$router->post('/logout', [AuthController::class, 'logout'], ['auth:allow_password_change']);

$router->get('/password/change', [PasswordController::class, 'show'], ['auth:allow_password_change']);
$router->post('/password/change', [PasswordController::class, 'update'], ['auth:allow_password_change']);

$router->get('/', [HomeController::class, 'index'], ['auth']);

$router->get('/admin', [AdminController::class, 'index'], ['auth', 'role:admin']);

return $router;

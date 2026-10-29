<?php
declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\HomeController;
use App\Controllers\ItemController;
use App\Controllers\PasswordController;
use App\Controllers\ReservationController;
use App\Support\Router;

/**
 * ルート一覧（明示登録したものだけが動く）
 * ミドルウェア:
 *   guest                       … 未ログインのみ（ログイン済みならホームへ）
 *   auth                        … ログイン必須＋パスワード変更済み
 *   auth:allow_password_change  … ログイン必須（パスワード変更前でも可）
 *   role:admin                  … 管理者のみ
 *   role:user                   … 一般利用者（生徒・教員）のみ
 * POST はすべて自動でCSRF照合される。{id} は1以上の整数だけに一致する。
 */
$router = new Router();

$router->get('/login', [AuthController::class, 'showLogin'], ['guest']);
$router->post('/login', [AuthController::class, 'login'], ['guest']);
$router->post('/logout', [AuthController::class, 'logout'], ['auth:allow_password_change']);

$router->get('/password/change', [PasswordController::class, 'show'], ['auth:allow_password_change']);
$router->post('/password/change', [PasswordController::class, 'update'], ['auth:allow_password_change']);

$router->get('/', [HomeController::class, 'index'], ['auth']);

// ---- 備品（ログインしていれば誰でも見られる） ----
$router->get('/items', [ItemController::class, 'index'], ['auth']);

// ---- 生徒・教員 ----
$user = ['auth', 'role:user'];
$router->get('/reservations/new', [ReservationController::class, 'create'], $user);
$router->post('/reservations/new', [ReservationController::class, 'store'], $user);
$router->get('/my/reservations', [ReservationController::class, 'index'], $user);
$router->get('/my/reservations/{id}', [ReservationController::class, 'show'], $user);
$router->post('/my/reservations/{id}/cancel', [ReservationController::class, 'cancel'], $user);
$router->post('/notifications/read', [ReservationController::class, 'readNotifications'], $user);

// ---- 管理担当者 ----
$admin = ['auth', 'role:admin'];
$router->get('/admin', [AdminController::class, 'index'], $admin);
$router->get('/admin/reservations', [AdminController::class, 'reservations'], $admin);
$router->get('/admin/reservations/{id}', [AdminController::class, 'show'], $admin);
$router->post('/admin/reservations/{id}/approve', [AdminController::class, 'approve'], $admin);
$router->post('/admin/reservations/{id}/reject', [AdminController::class, 'reject'], $admin);
$router->post('/admin/reservations/{id}/cancel', [AdminController::class, 'cancel'], $admin);
$router->post('/admin/reservations/{id}/checkout', [AdminController::class, 'checkout'], $admin);
$router->post('/admin/reservations/{id}/return', [AdminController::class, 'returnItems'], $admin);

return $router;

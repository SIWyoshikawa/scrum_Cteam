<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\Request;
use App\Support\Response;

final class AdminController extends Controller
{
    /** GET /admin（管理担当者のみ。ルート側の role:admin で保護） */
    public function index(Request $request, array $params): Response
    {
        return $this->view('admin/index', ['title' => '管理メニュー']);
    }
}

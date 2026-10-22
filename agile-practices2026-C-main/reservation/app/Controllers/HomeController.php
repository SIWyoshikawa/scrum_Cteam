<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Support\Request;
use App\Support\Response;

final class HomeController extends Controller
{
    /** GET /（ログイン後のトップ。今後ここに「施設・備品一覧」などへのリンクを足す） */
    public function index(Request $request, array $params): Response
    {
        return $this->view('home/index', ['title' => 'ホーム']);
    }
}

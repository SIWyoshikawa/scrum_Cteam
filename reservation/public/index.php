<?php
declare(strict_types=1);

/**
 * 単一入口（フロントコントローラ）
 * すべてのリクエストはここに集まり、ルーター経由で処理される。
 * このファイル以外のPHPは public/ の外にあり、ブラウザから直接実行できない。
 */

// PHP組み込みサーバ（php -S）で動かすときだけ：CSSなど実在する静的ファイルはそのまま返す
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
    if ($file !== __DIR__ . '/' && is_file($file) && !str_ends_with($file, '.php')) {
        return false;
    }
}

$app = require dirname(__DIR__) . '/app/bootstrap.php';
$app->run();

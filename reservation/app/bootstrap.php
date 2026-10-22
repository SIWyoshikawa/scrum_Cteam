<?php
declare(strict_types=1);

/**
 * 起動処理：設定読み込み・PHPの安全設定・クラス自動読み込み・エラーハンドラ登録
 */

use App\Support\App;
use App\Support\Env;
use App\Support\ErrorHandler;
use App\Support\Logger;

define('BASE_PATH', dirname(__DIR__));

// ---- PHPの安全設定（php.ini を変えられない環境でも最低限ここで固定） ----
ini_set('display_errors', '0');          // 画面にエラー詳細を出さない（DD 6.7）
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
date_default_timezone_set('Asia/Tokyo'); // 画面表示・学内運用日はJST。DB保存はUTC（DB設計書 DB-015）
mb_internal_encoding('UTF-8');
header_remove('X-Powered-By');

// ---- クラス自動読み込み（App\Xxx\Yyy → app/Xxx/Yyy.php） ----
spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $file = BASE_PATH . '/app/' . $relative . '.php';
    if (is_file($file)) {
        require $file;
    }
});

require BASE_PATH . '/app/Support/helpers.php';

// ---- 設定読み込み（秘密情報は .env に置き、Gitに入れない） ----
Env::load(BASE_PATH . '/.env');
$config = require BASE_PATH . '/config/app.php';

$logger = new Logger($config['log_path']);
$errorHandler = new ErrorHandler($logger);
$errorHandler->register();

return new App($config, $logger, $errorHandler);

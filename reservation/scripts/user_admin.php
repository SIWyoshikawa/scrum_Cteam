<?php
declare(strict_types=1);

/**
 * アカウントの停止・再有効化・仮パスワード再発行（DB設計書 5-6 / 5-7）※Webからは実行できない
 * 管理画面ができるまでの代わり。どの操作も audit_logs に記録される。
 *
 * 使い方:
 *   PHP=/Applications/XAMPP/xamppfiles/bin/php
 *   $PHP scripts/user_admin.php suspend        --user-id=s0001   # 停止＋全端末ログアウト
 *   $PHP scripts/user_admin.php reactivate     --user-id=s0001   # 再有効化（古いログインは復活しない）
 *   $PHP scripts/user_admin.php reset-password --user-id=s0001   # 仮パスワード再発行＋全端末ログアウト
 */

use App\Support\HttpException;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$app = require dirname(__DIR__) . '/app/bootstrap.php';
restore_exception_handler();

$command = $argv[1] ?? '';
// getopt() は最初の非オプション引数（suspend など）で止まるので自前で読む
$loginId = '';
foreach (array_slice($argv, 2) as $arg) {
    if (str_starts_with($arg, '--user-id=')) {
        $loginId = trim(substr($arg, strlen('--user-id=')));
    }
}

if (!in_array($command, ['suspend', 'reactivate', 'reset-password'], true) || $loginId === '') {
    fwrite(STDERR, "使い方: user_admin.php (suspend|reactivate|reset-password) --user-id=ユーザーID\n");
    exit(1);
}

$user = $app->users()->findByLoginId($loginId);
if ($user === null) {
    fwrite(STDERR, "ユーザーが見つかりません: {$loginId}\n");
    exit(1);
}

$accounts = $app->accounts();
try {
    switch ($command) {
        case 'suspend':
            $accounts->suspend(null, $user['id']);
            echo "停止しました: {$loginId}（ログイン中の端末はすべてログアウトされました）\n";
            break;
        case 'reactivate':
            $accounts->reactivate(null, $user['id']);
            echo "再有効化しました: {$loginId}\n";
            break;
        case 'reset-password':
            $temp = $accounts->resetPassword(null, $user['id']);
            echo "仮パスワードを再発行しました: {$loginId}\n";
            echo "仮パスワード: {$temp}\n";
            echo "※ ここにしか表示されません。本人確認のうえ安全な方法で渡してください。\n";
            break;
    }
} catch (HttpException $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}

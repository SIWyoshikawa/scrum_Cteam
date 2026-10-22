<?php
declare(strict_types=1);

/**
 * 利用者の事前登録（DB設計書 5-1）※Webからは実行できない
 * 管理画面ができるまでは、初期管理者を含めてこのコマンドで登録する。
 * 仮パスワードを自動で作り、1回だけ表示する。本人は初回ログイン時に変更が必須。
 *
 * 使い方（Mac の XAMPP）:
 *   PHP=/Applications/XAMPP/xamppfiles/bin/php
 *   $PHP scripts/create_user.php --user-id=admin01 --email=admin01@example.com --role=admin
 *   $PHP scripts/create_user.php --user-id=s0001   --email=s0001@example.com   --role=student
 *   $PHP scripts/create_user.php --user-id=t0001   --email=t0001@example.com   --role=teacher
 *
 * 登録するのは架空のデータのみ（NF-002）。メールアドレスは example.com などを使う。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$app = require dirname(__DIR__) . '/app/bootstrap.php';
restore_exception_handler(); // CLIではエラー内容をそのまま表示する

$opts = getopt('', ['user-id:', 'email:', 'role:']);
$loginId = trim((string) ($opts['user-id'] ?? ''));
$email = trim((string) ($opts['email'] ?? ''));
$role = (string) ($opts['role'] ?? '');

$errors = [];
if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $loginId)) {
    $errors[] = '--user-id は半角英数字と . _ - の3〜50文字にしてください';
}
if ($email === '' || strlen($email) > 255 || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = '--email は必須です（正しい形式で255文字以内）';
}
if (!in_array($role, ['student', 'teacher', 'admin'], true)) {
    $errors[] = '--role は student / teacher / admin のどれかを指定してください';
}
if ($errors === []) {
    if ($app->users()->findByLoginId($loginId) !== null) {
        $errors[] = 'そのユーザーIDは既に使われています';
    }
    if ($app->users()->emailExists($email)) {
        $errors[] = 'そのメールアドレスは既に使われています';
    }
}
if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

$result = $app->accounts()->createUser(null, $loginId, $email, $role);

echo "登録しました: id={$result['id']} user_id={$loginId} role={$role}" . PHP_EOL;
echo "仮パスワード: {$result['temp_password']}" . PHP_EOL;
echo '※ 仮パスワードはここにしか表示されません。安全な方法で本人に渡してください。' . PHP_EOL;
echo '※ 初回ログイン時にパスワード変更が必要です。' . PHP_EOL;

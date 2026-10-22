<?php
declare(strict_types=1);

/**
 * アプリ用DBユーザー（rsv_app）のパスワードを自動で作り直すスクリプト ※Webからは実行できない
 *
 * 手作業でのコピペ（phpMyAdmin と .env の両方に同じパスワードを書く）で
 * 1文字ずれる事故を防ぐため、次をまとめて自動で行う：
 *   1. ランダムなパスワードを作る
 *   2. 管理者（root）でDBに入り、database/setup/02_create_app_user.sql を実行（作成・上書き・権限付与）
 *   3. .env の DB_PASSWORD を書き換える
 *   4. rsv_app で実際に接続できるか確認する
 * パスワードは画面に表示しない（.env にだけ保存される）。
 *
 * 使い方（Mac の XAMPP）:
 *   /Applications/XAMPP/xamppfiles/bin/php /Applications/XAMPP/xamppfiles/htdocs/reservation/scripts/setup_db_user.php
 *   → 「DBの管理者(root)のパスワード」を聞かれる。XAMPPの初期状態なら何も入力せず Enter。
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$base = dirname(__DIR__);
$envFile = $base . '/.env';
$sqlFile = $base . '/database/setup/02_create_app_user.sql';

if (!is_file($envFile)) {
    if (!copy($base . '/.env.example', $envFile)) {
        fail('.env を作れませんでした');
    }
    echo ".env が無かったので .env.example から作りました\n";
}
$env = parse_env((string) file_get_contents($envFile));
$host = $env['DB_HOST'] ?? '127.0.0.1';
$port = (int) ($env['DB_PORT'] ?? '3306');
$dbName = $env['DB_NAME'] ?? 'school_reservation';
$appUser = $env['DB_USER'] ?? 'rsv_app';

$opts = getopt('', ['admin-user:']);
$adminUser = (string) ($opts['admin-user'] ?? 'root');

// ---- 1. 管理者でDBに接続 ----
$adminPass = prompt_hidden("DBの管理者({$adminUser})のパスワード（XAMPPの初期状態なら何も入力せず Enter）: ");
try {
    $admin = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $adminUser, $adminPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
} catch (PDOException $e) {
    fail("管理者({$adminUser})でDBに接続できませんでした。XAMPPで MySQL が起動しているか、管理者のパスワード、.env の DB_HOST（XAMPPなら localhost）を確認してください。\n  詳細: " . $e->getMessage());
}

$exists = $admin->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = " . $admin->quote($dbName))->fetchColumn();
if ((int) $exists !== 1) {
    fail("データベース {$dbName} がありません。先に phpMyAdmin で database/migrations/001_auth_schema.sql を実行してください。");
}

// ---- 2. パスワードを作り、02_create_app_user.sql を実行 ----
$password = bin2hex(random_bytes(16)); // 英数字だけ＝SQLや.envで壊れない
$sql = str_replace('CHANGE_ME_TO_LONG_RANDOM_PASSWORD', $password, (string) file_get_contents($sqlFile));
if ($appUser !== 'rsv_app') {
    $sql = str_replace("'rsv_app'", $admin->quote($appUser), $sql);
}
$statements = array_filter(array_map('trim', explode(';', implode("\n", array_filter(
    preg_split('/\R/u', $sql) ?: [],
    static fn (string $line) => !str_starts_with(ltrim($line), '--'),
)))));
foreach ($statements as $statement) {
    $admin->exec($statement);
}
// 古い 02_create_app_user.sql（ALTER USER が無い版）でも確実にパスワードを上書きする
foreach (['localhost', '127.0.0.1'] as $h) {
    $admin->exec('ALTER USER ' . $admin->quote($appUser) . '@' . $admin->quote($h) . ' IDENTIFIED BY ' . $admin->quote($password));
}
$admin->exec('FLUSH PRIVILEGES');
echo "✔ DBユーザー {$appUser} のパスワードを設定し、権限を付けました\n";

// ---- 3. .env の DB_PASSWORD を書き換える ----
$content = (string) file_get_contents($envFile);
$content = str_contains($content, "\r\n") ? str_replace("\r\n", "\n", $content) : $content;
if (preg_match('/^DB_PASSWORD=.*$/m', $content)) {
    $content = (string) preg_replace('/^DB_PASSWORD=.*$/m', 'DB_PASSWORD=' . $password, $content);
} else {
    $content = rtrim($content, "\n") . "\nDB_PASSWORD={$password}\n";
}
if (file_put_contents($envFile, $content) === false) {
    fail('.env に書き込めませんでした');
}
echo "✔ .env の DB_PASSWORD を書き換えました（パスワードは表示しません）\n";

// ---- 4. rsv_app で本当に入れるか確認 ----
try {
    $app = new PDO("mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4", $appUser, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
    $roles = (int) $app->query('SELECT COUNT(*) FROM roles')->fetchColumn();
    echo "✔ {$appUser} で接続できました（roles に {$roles} 件）\n\n完了です。次は create_user.php で利用者を登録してください。\n";
} catch (PDOException $e) {
    fail("設定はしましたが、{$appUser} で接続できませんでした。\n  詳細: " . $e->getMessage());
}

// ---------------------------------------------------------------

/** @return array<string,string> */
function parse_env(string $content): array
{
    $values = [];
    foreach (preg_split('/\R/u', $content) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $values[trim($k)] = trim(trim($v), "\"'");
    }
    return $values;
}

function prompt_hidden(string $label): string
{
    echo $label;
    $tty = function_exists('posix_isatty') ? posix_isatty(STDIN) : true;
    if ($tty) {
        shell_exec('stty -echo 2>/dev/null');
    }
    $value = rtrim((string) fgets(STDIN), "\r\n");
    if ($tty) {
        shell_exec('stty echo 2>/dev/null');
        echo PHP_EOL;
    }
    return $value;
}

function fail(string $message): never
{
    fwrite(STDERR, "✘ {$message}\n");
    exit(1);
}

<?php
declare(strict_types=1);

/**
 * 動いているサーバに対するセキュリティ確認（SEC-03/04/05/06/08/09/10/11/12 の一部を自動化）
 *
 * サーバと同じPCで実行する。テスト用の架空ユーザー（smoke_ で始まるID）を自動で作り、
 * 最後に停止状態にする（DB設計上ユーザーは削除しないため、停止した行は残る）。
 * ロック確認のため、存在しないID「locktest_...」で5回ログインに失敗する。
 *
 * 使い方:
 *   BASE_URL=http://localhost/reservation /Applications/XAMPP/xamppfiles/bin/php tests/Security/smoke_test.php
 */

use App\Repositories\SessionRepository;

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$base = rtrim((string) getenv('BASE_URL'), '/');
if ($base === '') {
    fwrite(STDERR, "BASE_URL を指定してください（例: BASE_URL=http://localhost/reservation）\n");
    exit(2);
}

$app = require dirname(__DIR__, 2) . '/app/bootstrap.php';
restore_exception_handler();
$pdo = $app->db->pdo();

require dirname(__DIR__) . '/HttpClient.php';

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  OK   ' : '  NG   ') . $label . ($ok || $detail === '' ? '' : "\n         {$detail}") . PHP_EOL;
}
function goesToLogin(array $r): bool
{
    return $r['status'] === 303 && str_contains($r['headers']['location'] ?? '', '/login');
}
function goesTo(array $r, string $path): bool
{
    return $r['status'] === 303 && str_ends_with($r['headers']['location'] ?? '', $path);
}

// ---- テスト用ユーザー作成（架空データ） ----
$tag = bin2hex(random_bytes(3));
$acc = $app->accounts();
$mk = function (string $role) use ($acc, $tag): array {
    $id = "smoke_{$role}_{$tag}";
    $r = $acc->createUser(null, $id, "テスト {$role}", "{$id}@example.invalid", $role);
    return ['login' => $id, 'id' => $r['id'], 'temp' => $r['temp_password'], 'pw' => 'Smoke-' . bin2hex(random_bytes(8))];
};
$student = $mk('student');
$teacher = $mk('teacher');
$admin = $mk('admin');

echo "対象: {$base}\nテスト用ユーザー: {$student['login']} / {$teacher['login']} / {$admin['login']}\n\n[ヘッダー・公開範囲]\n";
$c = new Client($base);
$r = $c->request('GET', '/login');
check('ログイン画面が表示される', $r['status'] === 200, "status={$r['status']}");
check('X-Powered-By を出さない', !isset($r['headers']['x-powered-by']));
check('CSPヘッダーがある', str_contains($r['headers']['content-security-policy'] ?? '', "default-src 'self'"));
check('X-Frame-Options: DENY', ($r['headers']['x-frame-options'] ?? '') === 'DENY');
check('Cache-Control: no-store', ($r['headers']['cache-control'] ?? '') === 'no-store');
$cookie = $r['headers']['set-cookie'] ?? '';
check('セッションCookieに HttpOnly', stripos($cookie, 'httponly') !== false, $cookie);
check('セッションCookieに SameSite=Lax', stripos($cookie, 'samesite=lax') !== false, $cookie);
foreach (['/.env', '/.git/config', '/config/app.php', '/app/bootstrap.php', '/storage/logs/',
          '/database/migrations/001_equipment_management.sql', '/scripts/create_user.php', '/docs/auth_database_design.md', '/README.md'] as $path) {
    $r = (new Client($base))->request('GET', $path);
    $leak = str_contains($r['body'], 'DB_PASSWORD') || str_contains($r['body'], '<?php') || str_contains($r['body'], 'CREATE TABLE');
    check("非公開ファイル {$path} が見えない", in_array($r['status'], [403, 404], true) && !$leak, "status={$r['status']}");
}

echo "\n[CSRF・メソッド]\n";
$c = new Client($base);
$c->request('GET', '/login');
$r = $c->request('POST', '/login', ['login_id' => $student['login'], 'password' => $student['temp']]);
check('CSRFトークンなしのログインは 403', $r['status'] === 403, "status={$r['status']}");
$r = $c->request('POST', '/login', ['_csrf' => str_repeat('0', 64), 'login_id' => $student['login'], 'password' => $student['temp']]);
check('偽CSRFトークンのログインは 403', $r['status'] === 403, "status={$r['status']}");
check('GET /logout は 405', $c->request('GET', '/logout')['status'] === 405);

echo "\n[ログイン]\n";
$r = (new Client($base))->login($student['login'], 'definitely-wrong-password');
check('パスワード違いは 401 で共通メッセージ', $r['status'] === 401 && str_contains($r['body'], 'ユーザーIDまたはパスワードが正しくありません'), "status={$r['status']}");
$r = (new Client($base))->login('no_such_user_' . $tag, 'x');
check('存在しないIDでも同じメッセージ（存在を明かさない）', $r['status'] === 401 && str_contains($r['body'], 'ユーザーIDまたはパスワードが正しくありません'));
$r = (new Client($base))->login(str_repeat('a', 51), 'x');
check('51文字以上のユーザーIDは 422', $r['status'] === 422, "status={$r['status']}");

$s = new Client($base);
$s->request('GET', '/login');
$sidBefore = $s->sid();
$r = $s->login($student['login'], $student['temp']);
check('仮パスワードでログイン → パスワード変更画面へ', goesTo($r, '/password/change'), "status={$r['status']} " . ($r['headers']['location'] ?? ''));
check('ログイン成功でセッションIDが変わる（固定攻撃対策）', $sidBefore !== '' && $s->sid() !== '' && $sidBefore !== $s->sid());
$row = $pdo->prepare('SELECT user_id, revoked_at, last_active_at, expires_at FROM user_sessions WHERE session_token_hash = :h');
$row->execute([':h' => SessionRepository::hashToken($s->sid())]);
$sessRow = $row->fetch();
check('user_sessions にセッションIDのハッシュが登録される', $sessRow !== false && (int) $sessRow['user_id'] === $student['id'] && $sessRow['revoked_at'] === null);
$row->execute([':h' => $s->sid()]);
check('セッションIDそのものは保存されていない', $row->fetch() === false);
check('保存時刻はUTC', $sessRow !== false && abs(strtotime($sessRow['last_active_at'] . ' UTC') - time()) < 120, (string) ($sessRow['last_active_at'] ?? ''));
check('expires_at は最終操作の30分後', $sessRow !== false
    && strtotime($sessRow['expires_at'] . ' UTC') - strtotime($sessRow['last_active_at'] . ' UTC') === 30 * 60);
$fix = new Client($base);
$fix->cookies['RSVSESSID'] = $sidBefore;
check('ログイン前の古いセッションIDでは入れない', goesToLogin($fix->request('GET', '/')));

echo "\n[初回パスワード変更（must_change_password）]\n";
check('変更前はホームを開けない（変更画面へ）', goesTo($s->request('GET', '/'), '/password/change'));
check('変更前は管理画面も開けない', goesTo($s->request('GET', '/admin'), '/password/change'));
$r = $s->changePassword('wrong-current-pw', $student['pw']);
check('現在のパスワード違いは 422', $r['status'] === 422 && str_contains($r['body'], '現在のパスワードが正しくありません'));
$r = $s->changePassword($student['temp'], 'short');
check('短すぎる新パスワードは 422', $r['status'] === 422);
$r = $s->changePassword($student['temp'], $student['pw'], $student['pw'] . 'x');
check('確認欄の不一致は 422', $r['status'] === 422);
$sidPre = $s->sid();
$r = $s->changePassword($student['temp'], $student['pw']);
check('正しく変更すると 303 でホームへ', goesTo($r, '/'), "status={$r['status']}");
check('変更後にセッションIDが変わる', $s->sid() !== $sidPre && $s->sid() !== '');
$u = $pdo->prepare('SELECT must_change_password FROM users WHERE id = :id');
$u->execute([':id' => $student['id']]);
$ur = $u->fetch();
check('must_change_password=FALSE になる', (int) $ur['must_change_password'] === 0);
$r = $s->request('GET', '/');
check('変更後はホームが見える', $r['status'] === 200 && str_contains($r['body'], $student['login']), "status={$r['status']}");
check('旧パスワードではログインできない', (new Client($base))->login($student['login'], $student['temp'])['status'] === 401);

echo "\n[権限（URL直打ち）]\n";
$r = $s->request('GET', '/admin');
check('生徒が /admin を直接開くと 403', $r['status'] === 403, "status={$r['status']}");
check('403画面にSQLやファイルパスが出ない', !preg_match('/SQLSTATE|\.php|Stack trace|PDO/i', $r['body']));
$t = new Client($base);
$t->login($teacher['login'], $teacher['temp']);
$t->changePassword($teacher['temp'], $teacher['pw']);
check('教員はホームを開ける', $t->request('GET', '/')['status'] === 200);
check('教員も /admin は 403（一般利用者と同じ扱い）', $t->request('GET', '/admin')['status'] === 403);
$a = new Client($base);
$a->login($admin['login'], $admin['temp']);
$r = $a->changePassword($admin['temp'], $admin['pw']);
check('管理者はパスワード変更後 /admin へ', goesTo($r, '/admin'), $r['headers']['location'] ?? '');
check('管理者は /admin を開ける', $a->request('GET', '/admin')['status'] === 200);
check('未ログインで /admin → ログイン画面へ', goesToLogin((new Client($base))->request('GET', '/admin')));

echo "\n[複数端末・停止・仮パスワード再発行]\n";
$s2 = new Client($base);
$r = $s2->login($student['login'], $student['pw']);
check('別の端末からも同時にログインできる', goesTo($r, '/') && $s->request('GET', '/')['status'] === 200 && $s2->request('GET', '/')['status'] === 200);
$acc->suspend(null, $student['id']);
check('停止すると端末1は即ログアウト', goesToLogin($s->request('GET', '/')));
check('停止すると端末2も即ログアウト', goesToLogin($s2->request('GET', '/')));
check('停止中はログインできない（共通メッセージ）', (new Client($base))->login($student['login'], $student['pw'])['status'] === 401);
$old = new Client($base);
$old->login($teacher['login'], $teacher['pw']);
$acc->reactivate(null, $student['id']);
$s3 = new Client($base);
check('再有効化後はログインできる', goesTo($s3->login($student['login'], $student['pw']), '/'));
$temp2 = $acc->resetPassword(null, $student['id']);
check('仮パスワード再発行で既存の端末はログアウト', goesToLogin($s3->request('GET', '/')));
$s4 = new Client($base);
check('再発行後は仮パスワードでログイン → 変更必須', goesTo($s4->login($student['login'], $temp2), '/password/change'));

echo "\n[ログアウト]\n";
$sidT = $old->sid();
$r = $old->postFrom('/', '/logout', []);
check('ログアウトは 303 でログイン画面へ', goesToLogin($r), "status={$r['status']}");
$replay = new Client($base);
$replay->cookies['RSVSESSID'] = $sidT;
check('ログアウト後に古いセッションIDを使っても入れない', goesToLogin($replay->request('GET', '/')));
$row->execute([':h' => SessionRepository::hashToken($sidT)]);
check('user_sessions の行は削除されず revoked_at が入る', ($row->fetch()['revoked_at'] ?? null) !== null);
check('未ログインの POST /logout は拒否', in_array((new Client($base))->request('POST', '/logout', ['_csrf' => 'x'])['status'], [401, 403], true));

echo "\n[ログイン試行制限]\n";
$target = 'locktest_' . $tag;
$statuses = [];
for ($i = 1; $i <= 6; $i++) {
    $statuses[] = (new Client($base))->login($target, 'wrong')['status'];
}
check('1〜4回目は 401', array_slice($statuses, 0, 4) === [401, 401, 401, 401], implode(',', $statuses));
check('5回目でロック（429）', $statuses[4] === 429, implode(',', $statuses));
check('ロック中は 429 のまま', $statuses[5] === 429, implode(',', $statuses));
$cnt = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE user_id_input = :in');
$cnt->execute([':in' => $target]);
check('存在しないIDの失敗も login_attempts に記録（ロック中の試行は数えない）', (int) $cnt->fetchColumn() === 5);

echo "\n[監査ログ]\n";
$q = $pdo->prepare("SELECT action, details FROM audit_logs WHERE entity_type = 'user' AND entity_id = :id ORDER BY id");
$q->execute([':id' => $student['id']]);
$actions = array_map(fn ($r) => $r['action'] . ':' . (json_decode((string) $r['details'], true)['result'] ?? '?'), $q->fetchAll());
foreach (['user_created:success', 'login_success:success', 'login_failure:failure', 'password_changed:failure',
          'password_changed:success', 'account_suspended:success', 'account_reactivated:success',
          'password_reset_by_admin:success'] as $need) {
    check("監査ログに {$need}", in_array($need, $actions, true));
}
$ipq = $pdo->prepare("SELECT details FROM audit_logs WHERE entity_type = 'user' AND entity_id = :id AND action = 'login_success' LIMIT 1");
$ipq->execute([':id' => $student['id']]);
$ip = json_decode((string) $ipq->fetchColumn(), true)['ip'] ?? null;
check('監査ログに接続元IPが記録される', is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP) !== false);
$pw = $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE details LIKE '%Smoke-%'")->fetchColumn();
check('監査ログにパスワードが含まれない', (int) $pw === 0);

echo "\n[エラー表示]\n";
$r = (new Client($base))->request('GET', '/items/' . rawurlencode("1' OR '1'='1"));
check('不正なIDのURLは 404、内部情報なし', $r['status'] === 404 && !preg_match('/SQLSTATE|Stack trace/i', $r['body']));
$r = (new Client($base))->request('GET', '/login?x=' . rawurlencode('<script>alert(1)</script>'));
check('URLに入れたスクリプトがそのまま出ない', !str_contains($r['body'], '<script>alert(1)</script>'));

// ---- 後片付け：テスト用ユーザーを停止（削除はしない設計） ----
foreach ([$student, $teacher, $admin] as $x) {
    try {
        $acc->suspend(null, $x['id']);
    } catch (Throwable) {
    }
}

echo "\n合計: {$pass} 件成功 / {$fail} 件失敗\n";
exit($fail === 0 ? 0 : 1);

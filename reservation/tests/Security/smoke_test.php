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

final class Client
{
    /** @var array<string,string> */
    public array $cookies = [];

    public function __construct(private readonly string $base)
    {
    }

    /** @return array{status:int, headers:array<string,string>, body:string} */
    public function request(string $method, string $path, array $form = []): array
    {
        $ch = curl_init($this->base . $path);
        $headers = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$headers): int {
                if (str_contains($line, ':')) {
                    [$k, $v] = explode(':', $line, 2);
                    $k = strtolower(trim($k));
                    $v = trim($v);
                    if ($k === 'set-cookie') {
                        [$pair] = explode(';', $v, 2);
                        [$ck, $cv] = explode('=', $pair, 2);
                        if ($cv === '' || $cv === 'deleted' || preg_match('/max-age=0/i', $v)) {
                            unset($this->cookies[$ck]);
                        } else {
                            $this->cookies[$ck] = $cv;
                        }
                        $headers['set-cookie'] = ($headers['set-cookie'] ?? '') . $v . "\n";
                    } else {
                        $headers[$k] = $v;
                    }
                }
                return strlen($line);
            },
        ]);
        if ($this->cookies !== []) {
            curl_setopt($ch, CURLOPT_COOKIE, http_build_query($this->cookies, '', '; '));
        }
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
        }
        $body = (string) curl_exec($ch);
        return ['status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), 'headers' => $headers, 'body' => $body];
    }

    public static function csrfFrom(string $html): string
    {
        return preg_match('/name="_csrf" value="([0-9a-f]{64})"/', $html, $m) ? $m[1] : '';
    }

    public function login(string $id, string $pass): array
    {
        $page = $this->request('GET', '/login');
        return $this->request('POST', '/login', ['_csrf' => self::csrfFrom($page['body']), 'login_id' => $id, 'password' => $pass]);
    }

    /** ログイン中の画面からトークンを取ってPOST */
    public function postFrom(string $pagePath, string $path, array $form): array
    {
        $page = $this->request('GET', $pagePath);
        return $this->request('POST', $path, ['_csrf' => self::csrfFrom($page['body'])] + $form);
    }

    public function changePassword(string $current, string $new, ?string $confirm = null): array
    {
        return $this->postFrom('/password/change', '/password/change', [
            'current_password' => $current, 'new_password' => $new, 'new_password_confirmation' => $confirm ?? $new,
        ]);
    }

    public function sid(): string
    {
        return $this->cookies['RSVSESSID'] ?? '';
    }
}

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
    $r = $acc->createUser(null, $id, "{$id}@example.invalid", $role);
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
          '/database/migrations/001_auth_schema.sql', '/scripts/create_user.php', '/README.md'] as $path) {
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
$row = $pdo->prepare('SELECT user_id, revoked_at, created_at FROM user_sessions WHERE session_token_hash = :h');
$row->execute([':h' => SessionRepository::hashToken($s->sid())]);
$sessRow = $row->fetch();
check('user_sessions にセッションIDのハッシュが登録される', $sessRow !== false && (int) $sessRow['user_id'] === $student['id'] && $sessRow['revoked_at'] === null);
$row->execute([':h' => $s->sid()]);
check('セッションIDそのものは保存されていない', $row->fetch() === false);
check('保存時刻はUTC', $sessRow !== false && abs(strtotime($sessRow['created_at'] . ' UTC') - time()) < 120, (string) ($sessRow['created_at'] ?? ''));
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
$u = $pdo->prepare('SELECT must_change_password, password_changed_at FROM users WHERE id = :id');
$u->execute([':id' => $student['id']]);
$ur = $u->fetch();
check('must_change_password=FALSE・password_changed_at 記録', (int) $ur['must_change_password'] === 0 && $ur['password_changed_at'] !== null);
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
$q = $pdo->prepare('SELECT action, result FROM auth_audit_logs WHERE target_user_id = :id ORDER BY id');
$q->execute([':id' => $student['id']]);
$actions = array_map(fn ($r) => $r['action'] . ':' . $r['result'], $q->fetchAll());
foreach (['user_created:success', 'login_success:success', 'login_failure:failure', 'password_changed:failure',
          'password_changed:success', 'account_suspended:success', 'account_reactivated:success',
          'password_reset_by_admin:success'] as $need) {
    check("監査ログに {$need}", in_array($need, $actions, true));
}
$ipq = $pdo->prepare("SELECT ip_address FROM auth_audit_logs WHERE target_user_id = :id AND action = 'login_success' LIMIT 1");
$ipq->execute([':id' => $student['id']]);
$ipRaw = $ipq->fetchColumn();
check('監査ログのIPは inet_pton 形式で保存', is_string($ipRaw) && inet_ntop($ipRaw) !== false);

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

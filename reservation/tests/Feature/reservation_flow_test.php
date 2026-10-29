<?php
declare(strict_types=1);

/**
 * 予約・承認・貸出・返却の通しテスト（動いているサーバに対して実行）
 *
 * テスト用の架空ユーザー（flow_ で始まるID）と、テスト用の備品（「テスト備品_…」全数2）を作り、
 * 最後にユーザーは停止、備品は利用停止にする（削除はしない設計のため行は残る）。
 *
 * 使い方:
 *   BASE_URL=http://localhost/reservation /Applications/XAMPP/xamppfiles/bin/php tests/Feature/reservation_flow_test.php
 */

use App\Support\Clock;
use App\Support\HttpException;

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
require dirname(__DIR__) . '/HttpClient.php';
$pdo = $app->db->pdo();

$pass = 0;
$fail = 0;
function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? '  OK   ' : '  NG   ') . $label . ($ok || $detail === '' ? '' : "\n         {$detail}") . PHP_EOL;
}
function status(PDO $pdo, int $id): string
{
    $s = $pdo->prepare('SELECT status FROM reservations WHERE id = :id');
    $s->execute([':id' => $id]);
    return (string) $s->fetchColumn();
}
function newId(array $r): int
{
    return preg_match('#/my/reservations/(\d+)$#', $r['headers']['location'] ?? '', $m) ? (int) $m[1] : 0;
}
/** 一覧などの画面を開いて、出ているメッセージ（flash）を拾う */
function flashOf(Client $c, string $path): string
{
    $body = $c->request('GET', $path)['body'];
    return preg_match_all('#<p class="alert alert-(?:info|error)" role="status">(.*?)</p>#s', $body, $m) ? implode(' / ', $m[1]) : '';
}

$today = Clock::todayJst();
$d = static fn (int $days): string => (new DateTimeImmutable($today))->modify("{$days} days")->format('Y-m-d');
$tag = bin2hex(random_bytes(3));

// ---- 準備：テスト用ユーザー・備品 ----
$acc = $app->accounts();
$users = [];
foreach (['student' => ['s1', 's2', 's3'], 'teacher' => ['t1'], 'admin' => ['a1', 'a2']] as $role => $keys) {
    foreach ($keys as $k) {
        $login = "flow_{$k}_{$tag}";
        $r = $acc->createUser(null, $login, "テスト {$k}", "{$login}@example.invalid", $role);
        $pw = 'Flow-' . bin2hex(random_bytes(8));
        $c = new Client($base);
        $c->login($login, $r['temp_password']);
        $c->changePassword($r['temp_password'], $pw);
        $users[$k] = ['id' => $r['id'], 'c' => $c];
    }
}
[$s1, $s2, $s3, $t1, $a1, $a2] = [$users['s1']['c'], $users['s2']['c'], $users['s3']['c'], $users['t1']['c'], $users['a1']['c'], $users['a2']['c']];

$pdo->prepare("INSERT INTO equipment (name, total_quantity, status, storage_location) VALUES (:n, 2, 'available', 'テスト棚')")
    ->execute([':n' => "テスト備品_{$tag}"]);
$eq = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO equipment (name, total_quantity, status) VALUES (:n, 50, 'available')")
    ->execute([':n' => "テスト予備_{$tag}"]);
$eqBig = (int) $pdo->lastInsertId();

$apply = static function (Client $c, string $start, string $end, array $qty, string $purpose = 'テストで使用'): array {
    return $c->postFrom('/reservations/new', '/reservations/new', [
        'start_date' => $start, 'end_date' => $end, 'purpose' => $purpose, 'qty' => $qty,
    ]);
};
$admin = static function (Client $c, int $id, string $action, array $form = []): array {
    return $c->postFrom("/admin/reservations/{$id}", "/admin/reservations/{$id}/{$action}", $form);
};

echo "対象: {$base}　今日（JST）: {$today}　テスト備品ID: {$eq}\n";

echo "\n[備品をさがす]\n";
$r = $s1->request('GET', '/items?q=' . rawurlencode("テスト備品_{$tag}"));
check('名前で検索できる', $r['status'] === 200 && str_contains($r['body'], "テスト備品_{$tag}"));
$r = $s1->request('GET', '/items?q=' . rawurlencode('%'));
check('「%」はワイルドカードにならない', $r['status'] === 200 && !str_contains($r['body'], "テスト備品_{$tag}"));
$r = $s1->request('GET', "/items?q=" . rawurlencode("テスト備品_{$tag}") . "&start={$today}&end={$d(1)}");
check('期間を入れると空き数が出る', str_contains($r['body'], '空き数を表示'));

echo "\n[予約申請]\n";
$r = $apply($s1, $today, $d(1), [$eq => '2']);
$id1 = newId($r);
check('申請すると詳細画面へ・状態は申請中', $id1 > 0 && status($pdo, $id1) === 'pending', "status={$r['status']}");
check('8日間の申請は 422', $apply($s1, $today, $d(7), [$eq => '1'])['status'] === 422);
check('過去の日付は 422', $apply($s1, $d(-1), $today, [$eq => '1'])['status'] === 422);
check('91日先は 422', $apply($s1, $d(91), $d(91), [$eq => '1'])['status'] === 422);
check('全数を超える数は 422', $apply($s1, $d(10), $d(10), [$eq => '3'])['status'] === 422);
check('利用目的が空は 422', $apply($s1, $d(10), $d(10), [$eq => '1'], '  ')['status'] === 422);
check('CSRFトークンなしは 403', $s1->request('POST', '/reservations/new', ['start_date' => $today, 'end_date' => $today, 'purpose' => 'x', 'qty' => [$eq => '1']])['status'] === 403);
$r = $apply($s2, $today, $d(1), [$eq => '1']);
$id2 = newId($r);
check('申請中どうしは期間が重なっても申請できる', $id2 > 0 && status($pdo, $id2) === 'pending');
$r = $apply($t1, $d(3), $d(4), [$eqBig => '5']);
$idT = newId($r);
check('教員も申請できる', $idT > 0);

echo "\n[権限・他人の予約]\n";
check('他人の予約の詳細は 404', $s2->request('GET', "/my/reservations/{$id1}")['status'] === 404);
$r = $s2->postFrom('/my/reservations', "/my/reservations/{$id1}/cancel", []);
check('他人の予約は取り消せない（404・状態そのまま）', $r['status'] === 404 && status($pdo, $id1) === 'pending');
check('生徒は /admin/reservations を開けない（403）', $s1->request('GET', '/admin/reservations')['status'] === 403);
check('生徒は承認できない（403）', $s1->postFrom('/my/reservations', "/admin/reservations/{$id1}/approve", [])['status'] === 403 && status($pdo, $id1) === 'pending');
check('管理者は予約申請の画面を使えない（403）', $a1->request('GET', '/reservations/new')['status'] === 403);

echo "\n[承認・却下]\n";
$admin($a1, $id1, 'approve', ['comment' => '受付で受け取ってください']);
check('承認すると承認済みになる', status($pdo, $id1) === 'approved');
$n = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = :u AND reservation_id = :r AND type = 'approved'");
$n->execute([':u' => $users['s1']['id'], ':r' => $id1]);
check('申請者にお知らせが届く', (int) $n->fetchColumn() === 1);
check('ホームにお知らせが出る', str_contains($s1->request('GET', '/')['body'], 'が承認されました'));
$admin($a1, $id2, 'approve');
check('在庫が足りない承認は止まる（状態は申請中のまま）', status($pdo, $id2) === 'pending');
check('止まった理由が画面に出る', str_contains(flashOf($a1, "/admin/reservations/{$id2}") . $a1->request('GET', "/admin/reservations/{$id2}")['body'], '不足'));
check('承認済みと重なる新しい申請は 422', $apply($s2, $today, $today, [$eq => '1'])['status'] === 422);
$admin($a1, $id2, 'reject', ['reason' => '']);
check('理由なしの却下はできない', status($pdo, $id2) === 'pending');
$admin($a1, $id2, 'reject', ['reason' => '在庫が足りないため']);
check('理由ありで却下できる', status($pdo, $id2) === 'rejected');
$admin($a1, $id2, 'approve');
check('却下済みはもう承認できない', status($pdo, $id2) === 'rejected');
$s1->postFrom("/my/reservations/{$id1}", "/my/reservations/{$id1}/cancel", []);
check('承認済みは本人が取り消せない', status($pdo, $id1) === 'approved');

echo "\n[貸出・返却]\n";
$admin($a1, $id1, 'checkout', ['note' => 'ケース付き']);
check('貸出すると貸出中になる', status($pdo, $id1) === 'on_loan');
$loan = $pdo->prepare('SELECT l.id, li.id AS item_id FROM loans l JOIN loan_items li ON li.loan_id = l.id WHERE l.reservation_id = :r');
$loan->execute([':r' => $id1]);
$lr = $loan->fetch();
check('loans / loan_items が作られる', $lr !== false);
$admin($a1, $id1, 'checkout');
check('二重に貸し出せない（loans は1件のまま）', (int) $pdo->query("SELECT COUNT(*) FROM loans WHERE reservation_id = {$id1}")->fetchColumn() === 1);
$admin($a1, $id1, 'return', ['return_qty' => [$lr['item_id'] => '3']]);
check('貸した数より多くは返せない', (int) $pdo->query("SELECT returned_quantity FROM loan_items WHERE id = {$lr['item_id']}")->fetchColumn() === 0);
$admin($a1, $id1, 'return', ['return_qty' => [$lr['item_id'] => '1'], 'condition_note' => '1つ先に返却']);
check('一部返却：返却済み1・まだ貸出中', (int) $pdo->query("SELECT returned_quantity FROM loan_items WHERE id = {$lr['item_id']}")->fetchColumn() === 1 && status($pdo, $id1) === 'on_loan');
$admin($a1, $id1, 'return', ['return_qty' => [$lr['item_id'] => '1']]);
check('全部返すと返却済み（completed）になる', status($pdo, $id1) === 'completed'
    && $pdo->query("SELECT status FROM loans WHERE id = {$lr['id']}")->fetchColumn() === 'returned');
check('returns に2回分の記録', (int) $pdo->query("SELECT COUNT(*) FROM returns WHERE loan_item_id = {$lr['item_id']}")->fetchColumn() === 2);

echo "\n[未来の予約は開始日まで貸し出せない]\n";
$r = $apply($s1, $d(5), $d(6), [$eq => '1']);
$idF = newId($r);
$admin($a1, $idF, 'approve');
$admin($a1, $idF, 'checkout');
check('利用開始日より前の貸出は止まる', status($pdo, $idF) === 'approved');
$admin($a1, $idF, 'cancel', ['reason' => '']);
check('理由なしで承認を取り消せない', status($pdo, $idF) === 'approved');
$admin($a1, $idF, 'cancel', ['reason' => '行事が中止になったため']);
check('理由ありで承認を取り消せる', status($pdo, $idF) === 'cancelled');

echo "\n[同時に承認しても1件だけ通る]\n";
$r = $apply($s2, $d(20), $d(21), [$eq => '2']);
$c1 = newId($r);
$r = $apply($s3, $d(21), $d(22), [$eq => '2']);
$c2 = newId($r);
$p1 = $a1->request('GET', "/admin/reservations/{$c1}");
$p2 = $a2->request('GET', "/admin/reservations/{$c2}");
$mh = curl_multi_init();
$handles = [];
foreach ([[$a1, $c1, $p1], [$a2, $c2, $p2]] as [$cl, $rid, $page]) {
    $h = curl_init("{$base}/admin/reservations/{$rid}/approve");
    curl_setopt_array($h, [
        CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS => http_build_query(['_csrf' => Client::csrfFrom($page['body'])]),
        CURLOPT_COOKIE => http_build_query($cl->cookies, '', '; '),
    ]);
    curl_multi_add_handle($mh, $h);
    $handles[] = $h;
}
do {
    curl_multi_exec($mh, $running);
    curl_multi_select($mh);
} while ($running > 0);
$approved = (status($pdo, $c1) === 'approved' ? 1 : 0) + (status($pdo, $c2) === 'approved' ? 1 : 0);
check('全数2の備品に「2個ずつ」の承認を同時に送っても、承認は1件だけ', $approved === 1, "approved={$approved}");

echo "\n[自己承認の禁止]\n";
$selfId = $app->reservationRepo()->create($users['a1']['id'], $d(30), $d(30), '管理者自身の申請（テスト）', [$eqBig => 1]);
try {
    $app->reservations()->approve($users['a1']['id'], $selfId, '', $today);
    check('自分の申請は承認できない', false);
} catch (HttpException $e) {
    check('自分の申請は承認できない', $e->status === 403 && status($pdo, $selfId) === 'pending');
}
$app->reservations()->approve($users['a2']['id'], $selfId, '', $today);
check('別の管理者なら承認できる', status($pdo, $selfId) === 'approved');

echo "\n[進行中は5件まで]\n";
for ($i = 0; $i < 5; $i++) {
    $apply($s3, $d(40 + $i), $d(40 + $i), [$eqBig => '1']);
}
$cnt = $pdo->prepare("SELECT COUNT(*) FROM reservations WHERE user_id = :u AND status IN ('pending','approved','on_loan')");
$cnt->execute([':u' => $users['s3']['id']]);
$active = (int) $cnt->fetchColumn();
$r = $apply($s3, $d(50), $d(50), [$eqBig => '1']);
check('5件を超える申請は 422', $active === 5 && $r['status'] === 422, "active={$active} status={$r['status']}");

echo "\n[延滞]\n";
$r = $apply($t1, $today, $today, [$eqBig => '1']);
$idO = newId($r);
$admin($a1, $idO, 'approve');
$admin($a1, $idO, 'checkout');
// 返却予定日を過去にずらして延滞を作る
$pdo->prepare('UPDATE reservations SET start_date = :s, end_date = :e WHERE id = :id')->execute([':s' => $d(-3), ':e' => $d(-1), ':id' => $idO]);
$pdo->prepare('UPDATE loans SET due_date = :e WHERE reservation_id = :id')->execute([':e' => $d(-1), ':id' => $idO]);
check('延滞が本人の一覧に「延滞」と出る', str_contains($t1->request('GET', '/my/reservations')['body'], 'status-overdue'));
check('延滞中は新しく申請できない（422）', $apply($t1, $d(2), $d(2), [$eqBig => '1'])['status'] === 422);
check('管理メニューに延滞件数が出る', str_contains($a1->request('GET', '/admin')['body'], 'tile-alert'));

echo "\n[期限切れ]\n";
$r = $apply($s2, $d(1), $d(1), [$eqBig => '1']);
$idE = newId($r);
$pdo->prepare('UPDATE reservations SET start_date = :s, end_date = :e WHERE id = :id')->execute([':s' => $d(-2), ':e' => $d(-2), ':id' => $idE]);
$s2->request('GET', '/my/reservations');
check('利用開始日を過ぎた申請中は「期限切れ」になる', status($pdo, $idE) === 'expired');

echo "\n[監査ログ]\n";
$q = $pdo->prepare("SELECT action FROM audit_logs WHERE entity_type = 'reservation' AND entity_id = :id ORDER BY id");
$q->execute([':id' => $id1]);
$actions = $q->fetchAll(PDO::FETCH_COLUMN);
foreach (['reservation_requested', 'reservation_approved', 'loan_checked_out', 'loan_partially_returned', 'loan_returned'] as $need) {
    check("監査ログに {$need}", in_array($need, $actions, true));
}
$q = $pdo->prepare("SELECT details FROM audit_logs WHERE entity_type = 'reservation' AND entity_id = :id AND action = 'reservation_rejected'");
$q->execute([':id' => $id2]);
$det = json_decode((string) $q->fetchColumn(), true);
check('却下の監査ログに理由・変更前後・相関IDがある', ($det['reason'] ?? '') === '在庫が足りないため'
    && ($det['before']['status'] ?? '') === 'pending' && ($det['after']['status'] ?? '') === 'rejected' && ($det['correlation_id'] ?? '') !== '');

echo "\n[画面の表示]\n";
$xss = '<script>alert(1)</script>';
$r = $apply($s2, $d(60), $d(60), [$eqBig => '1'], $xss);
$idX = newId($r);
$body = $a1->request('GET', "/admin/reservations/{$idX}")['body'];
check('利用目的に入れたタグはそのまま出ない（XSS）', !str_contains($body, $xss) && str_contains($body, '&lt;script&gt;'));
foreach (['/', '/items', '/reservations/new', '/my/reservations'] as $path) {
    check("生徒 {$path} が開ける", $s1->request('GET', $path)['status'] === 200);
}
foreach (['/admin', '/admin/reservations', '/admin/reservations?tab=all', "/admin/reservations/{$id1}"] as $path) {
    check("管理者 {$path} が開ける", $a1->request('GET', $path)['status'] === 200);
}
check('存在しない予約は 404', $a1->request('GET', '/admin/reservations/999999999')['status'] === 404);

// ---- 後片付け ----
foreach ($users as $u) {
    try {
        $acc->suspend(null, $u['id']);
    } catch (Throwable) {
    }
}
$pdo->prepare("UPDATE equipment SET status = 'inactive' WHERE id IN (:a, :b)")->execute([':a' => $eq, ':b' => $eqBig]);

echo "\n合計: {$pass} 件成功 / {$fail} 件失敗\n";
exit($fail === 0 ? 0 : 1);

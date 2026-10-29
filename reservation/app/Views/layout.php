<?php
/**
 * 共通レイアウト
 * 注意：出力は必ず h() を通す。style="" や onclick="" などのインライン記述は
 * CSP（Content-Security-Policy）でブロックされるので、CSS/JSは assets/ に置く。
 *
 * @var string $content 各画面の本文（描画済みHTML）
 */
$currentUser = $currentUser ?? null;
$flashes = $flashes ?? [];
$title = $title ?? '';
?>
<!doctype html>
<html lang="ja">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($title) ?> | <?= h($appName) ?></title>
  <link rel="stylesheet" href="<?= h(url('/assets/css/app.css')) ?>">
</head>
<body>
<header class="site-header<?= $currentUser !== null ? ' has-nav' : '' ?>">
  <a class="brand" href="<?= h(url('/')) ?>"><?= h($appName) ?></a>
  <?php if ($currentUser !== null): ?>
    <nav class="nav">
      <span class="user">
        <?= h($currentUser['name']) ?>
        <span class="login-id">(<?= h($currentUser['login_id']) ?>)</span>
        <span class="badge badge-<?= h($currentUser['role']) ?>"><?= h($currentUser['role_display']) ?></span>
      </span>
      <?php if (!$currentUser['must_change_password']): ?>
        <?php if ($currentUser['role'] === 'admin'): ?>
          <a href="<?= h(url('/admin')) ?>">管理メニュー</a>
          <a href="<?= h(url('/admin/reservations')) ?>">予約の管理</a>
        <?php else: ?>
          <a href="<?= h(url('/items')) ?>">備品をさがす</a>
          <a href="<?= h(url('/my/reservations')) ?>">自分の予約</a>
        <?php endif; ?>
      <?php endif; ?>
      <a href="<?= h(url('/password/change')) ?>">パスワード変更</a>
      <form method="post" action="<?= h(url('/logout')) ?>" class="inline">
        <?= csrf_field() ?>
        <button type="submit" class="link-button">ログアウト</button>
      </form>
    </nav>
  <?php endif; ?>
</header>

<main class="container">
  <?php foreach ($flashes as $flash): ?>
    <p class="alert alert-<?= h($flash['type']) ?>" role="status"><?= h($flash['message']) ?></p>
  <?php endforeach; ?>
  <?= $content /* 各テンプレート内でエスケープ済み */ ?>
</main>
</body>
</html>

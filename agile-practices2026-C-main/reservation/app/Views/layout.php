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
        <?= h($currentUser['login_id']) /* 表示名の列が無いため当面はユーザーID（PO確認待ち） */ ?>
        <span class="badge badge-<?= h($currentUser['role']) ?>"><?= h($currentUser['role_display']) ?></span>
      </span>
      <?php if ($currentUser['role'] === 'admin' && !$currentUser['must_change_password']): ?>
        <a href="<?= h(url('/admin')) ?>">管理メニュー</a>
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

<?php
/**
 * @var string      $loginId
 * @var string|null $error
 * @var string|null $notice
 */
?>
<div class="login-wrap">
  <section class="card login-card">
    <h1><span class="icon" aria-hidden="true">👤</span>ログイン画面</h1>

    <?php if ($notice !== null): ?>
      <p class="alert alert-info" role="status"><?= h($notice) ?></p>
    <?php endif; ?>
    <?php if ($error !== null): ?>
      <p class="alert alert-error" role="alert"><?= h($error) ?></p>
    <?php endif; ?>

    <form method="post" action="<?= h(url('/login')) ?>" novalidate>
      <?= csrf_field() ?>
      <label for="login_id">User ID</label>
      <input id="login_id" name="login_id" type="text" maxlength="50" required
             autocomplete="username" value="<?= h($loginId) ?>">

      <label for="password">Password</label>
      <input id="password" name="password" type="password" maxlength="1024" required
             autocomplete="current-password">

      <div class="actions">
        <button type="submit" class="button">ログイン</button>
      </div>
    </form>
    <p class="help">30分間操作がないと自動的にログアウトします。</p>
  </section>
</div>

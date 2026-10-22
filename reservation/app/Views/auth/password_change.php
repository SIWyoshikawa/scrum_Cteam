<?php
/**
 * @var list<string> $errors
 * @var array<string,mixed> $currentUser
 */
?>
<section class="card narrow">
  <h1>パスワード変更</h1>

  <?php if ($currentUser['must_change_password']): ?>
    <p class="alert alert-info" role="status">
      仮パスワードでログインしています。利用を始める前に、新しいパスワードを設定してください。
    </p>
  <?php endif; ?>

  <?php if ($errors !== []): ?>
    <div class="alert alert-error" role="alert">
      <ul class="errors">
        <?php foreach ($errors as $error): ?>
          <li><?= h($error) ?></li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" action="<?= h(url('/password/change')) ?>" novalidate>
    <?= csrf_field() ?>
    <!-- パスワード管理ツールが正しく扱えるよう、ユーザーIDを隠し項目で添える -->
    <input type="text" name="username" value="<?= h($currentUser['login_id']) ?>"
           autocomplete="username" hidden readonly>

    <label for="current_password">現在のパスワード</label>
    <input id="current_password" name="current_password" type="password" maxlength="1024" required
           autocomplete="current-password">

    <label for="new_password">新しいパスワード</label>
    <input id="new_password" name="new_password" type="password" maxlength="1024" required
           autocomplete="new-password" aria-describedby="pw-rule">
    <p id="pw-rule" class="help">12文字以上。ユーザーIDを含めない。</p>

    <label for="new_password_confirmation">新しいパスワード（確認）</label>
    <input id="new_password_confirmation" name="new_password_confirmation" type="password" maxlength="1024" required
           autocomplete="new-password">

    <button type="submit" class="button">変更する</button>
  </form>
</section>

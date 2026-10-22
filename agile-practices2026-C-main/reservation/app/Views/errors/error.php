<?php
/**
 * エラー画面。内部情報（SQL・ファイル名など）は絶対に出さない。
 * @var int    $status
 * @var string $message
 * @var string $ref
 */
?>
<section class="card narrow">
  <h1>エラー（<?= h($status) ?>）</h1>
  <p class="alert alert-error" role="alert"><?= h($message) ?></p>
  <p class="help">問い合わせの際は参照ID <code><?= h($ref) ?></code> をお伝えください。</p>
  <p><a href="<?= h(url('/')) ?>">トップへ戻る</a></p>
</section>

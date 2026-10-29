<?php
/**
 * 管理メニュー（GET /admin）
 * @var array<string,int> $counts 状態ごとの件数
 * @var int $overdue 延滞中の貸出数
 * @var string $today
 */
$tiles = [
    ['pending',  '申請中',   $counts['pending'],  '承認・却下を待っています'],
    ['approved', '受け取り待ち', $counts['approved'], '承認済み。窓口で貸出を記録'],
    ['on_loan',  '貸出中',   $counts['on_loan'],  '返却を待っています'],
];
?>
<section class="card">
  <h1>管理メニュー</h1>
  <p class="help">今日（学内運用日）：<?= jp_date($today) ?></p>

  <ul class="tile-grid">
    <?php foreach ($tiles as [$tab, $label, $n, $desc]): ?>
      <li>
        <a class="tile" href="<?= h(url('/admin/reservations?tab=' . $tab)) ?>">
          <span class="tile-label"><?= h($label) ?></span>
          <span class="tile-num"><?= h($n) ?><small> 件</small></span>
          <span class="tile-desc"><?= h($desc) ?></span>
        </a>
      </li>
    <?php endforeach; ?>
    <li>
      <a class="tile<?= $overdue > 0 ? ' tile-alert' : '' ?>" href="<?= h(url('/admin/reservations?tab=on_loan')) ?>">
        <span class="tile-label">延滞</span>
        <span class="tile-num"><?= h($overdue) ?><small> 件</small></span>
        <span class="tile-desc"><?= $overdue > 0 ? '返却予定日を過ぎています' : '延滞はありません' ?></span>
      </a>
    </li>
  </ul>

  <p><a href="<?= h(url('/admin/reservations?tab=all')) ?>">すべての予約を見る</a> ／ <a href="<?= h(url('/items')) ?>">備品一覧</a></p>
  <p class="help">備品の登録・変更・利用停止、ユーザー登録の画面は次の工程で追加します（ユーザー登録は当面コマンド）。</p>
</section>

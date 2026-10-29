<?php
/**
 * 自分の予約の詳細（GET /my/reservations/{id}）
 * @var array<string,mixed> $r
 * @var list<array<string,mixed>> $items
 * @var array<string,mixed>|null $loan
 * @var list<array<string,mixed>> $loanItems
 * @var string $today
 */
$overdue = $r['status'] === 'on_loan' && $r['end_date'] < $today;
?>
<p class="breadcrumb"><a href="<?= h(url('/my/reservations')) ?>">← 自分の予約・貸出</a></p>

<section class="card">
  <div class="section-head">
    <h1>予約 #<?= h($r['id']) ?></h1>
    <?= status_badge($r['status'], $overdue) ?>
  </div>

  <?php if ($overdue): ?>
    <p class="alert alert-error" role="alert">返却予定日（<?= jp_date($r['end_date']) ?>）を過ぎています。すぐに窓口へ返却してください。返却するまで新しい申請はできません。</p>
  <?php elseif ($r['status'] === 'approved'): ?>
    <p class="alert alert-info">承認されました。<?= jp_date($r['start_date']) ?> から窓口で受け取れます。</p>
  <?php endif; ?>

  <dl class="detail">
    <dt>期間</dt><dd><?= jp_date($r['start_date']) ?> 〜 <?= jp_date($r['end_date']) ?></dd>
    <dt>利用目的</dt><dd class="pre"><?= h($r['purpose']) ?></dd>
    <dt>申請日時</dt><dd><?= h(App\Support\Clock::toJst((string) $r['created_at'])) ?></dd>
    <?php if ($r['reviewed_at'] !== null): ?>
      <dt><?= $r['status'] === 'rejected' ? '却下' : '承認' ?>日時</dt>
      <dd><?= h(App\Support\Clock::toJst((string) $r['reviewed_at'])) ?></dd>
    <?php endif; ?>
    <?php if (($r['review_reason'] ?? '') !== ''): ?>
      <dt><?= $r['status'] === 'rejected' ? '却下の理由' : '管理者からのコメント' ?></dt><dd class="pre"><?= h($r['review_reason']) ?></dd>
    <?php endif; ?>
    <?php if ($r['status'] === 'cancelled' && ($r['cancellation_reason'] ?? '') !== ''): ?>
      <dt>取消の理由</dt><dd class="pre"><?= h($r['cancellation_reason']) ?></dd>
    <?php endif; ?>
  </dl>

  <h2>備品</h2>
  <div class="table-wrap">
    <table class="data">
      <thead><tr><th scope="col">備品</th><th scope="col" class="num">数</th>
        <?php if ($loan !== null): ?><th scope="col" class="num">返却済み</th><?php endif; ?></tr></thead>
      <tbody>
        <?php if ($loan !== null): ?>
          <?php foreach ($loanItems as $it): ?>
            <tr><td><?= h($it['name']) ?></td><td class="num"><?= h($it['quantity']) ?></td><td class="num"><?= h($it['returned_quantity']) ?></td></tr>
          <?php endforeach; ?>
        <?php else: ?>
          <?php foreach ($items as $it): ?>
            <tr><td><?= h($it['name']) ?></td><td class="num"><?= h($it['quantity']) ?></td></tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($r['status'] === 'pending'): ?>
    <form method="post" action="<?= h(url('/my/reservations/' . $r['id'] . '/cancel')) ?>" class="danger-zone">
      <?= csrf_field() ?>
      <p class="help">申請中のあいだは、自分で取り消せます。承認後の取消は窓口に相談してください。</p>
      <input type="checkbox" id="confirm-cancel" name="confirm" value="1" required>
      <label for="confirm-cancel" class="plain-label">この予約を取り消す</label>
      <button type="submit" class="button button-danger">取り消す</button>
    </form>
  <?php endif; ?>
</section>

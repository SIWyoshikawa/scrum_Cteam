<?php
/**
 * 予約の詳細と操作（GET /admin/reservations/{id}）
 * @var array<string,mixed> $r
 * @var list<array<string,mixed>> $items （free = この期間の空き数。この予約自身の分は除く）
 * @var array<string,mixed>|null $loan
 * @var list<array<string,mixed>> $loanItems
 * @var list<array<string,mixed>> $returns
 * @var string $today
 * @var bool $isSelf 自分の申請か（自己承認禁止）
 */
$overdue = $r['status'] === 'on_loan' && $r['end_date'] < $today;
$base = '/admin/reservations/' . $r['id'];
?>
<p class="breadcrumb"><a href="<?= h(url('/admin/reservations')) ?>">← 予約の管理</a></p>

<section class="card">
  <div class="section-head">
    <h1>予約 #<?= h($r['id']) ?></h1>
    <?= status_badge($r['status'], $overdue) ?>
  </div>

  <dl class="detail">
    <dt>申請者</dt><dd><?= h($r['user_name']) ?>（<?= h($r['login_id']) ?>）</dd>
    <dt>期間</dt><dd><?= jp_date($r['start_date']) ?> 〜 <?= jp_date($r['end_date']) ?></dd>
    <dt>利用目的</dt><dd class="pre"><?= h($r['purpose']) ?></dd>
    <dt>申請日時</dt><dd><?= h(App\Support\Clock::toJst((string) $r['created_at'])) ?></dd>
    <?php if ($r['reviewed_at'] !== null): ?>
      <dt>審査</dt><dd><?= h(App\Support\Clock::toJst((string) $r['reviewed_at'])) ?>（<?= h($r['reviewer_name'] ?? '') ?>）</dd>
    <?php endif; ?>
    <?php if (($r['review_reason'] ?? '') !== ''): ?>
      <dt><?= $r['status'] === 'rejected' ? '却下の理由' : 'コメント' ?></dt><dd class="pre"><?= h($r['review_reason']) ?></dd>
    <?php endif; ?>
    <?php if (($r['cancellation_reason'] ?? '') !== ''): ?>
      <dt>取消の理由</dt><dd class="pre"><?= h($r['cancellation_reason']) ?></dd>
    <?php endif; ?>
    <?php if ($loan !== null): ?>
      <dt>貸出</dt><dd><?= h(App\Support\Clock::toJst((string) $loan['lent_at'])) ?>（<?= h($loan['handed_out_by_name']) ?>）／返却予定 <?= jp_date((string) $loan['due_date']) ?></dd>
      <?php if (($loan['note'] ?? '') !== ''): ?><dt>貸出メモ</dt><dd class="pre"><?= h($loan['note']) ?></dd><?php endif; ?>
    <?php endif; ?>
  </dl>

  <?php if ($loan === null): ?>
    <h2>備品</h2>
    <div class="table-wrap">
      <table class="data">
        <thead><tr><th scope="col">備品</th><th scope="col">保管場所</th><th scope="col" class="num">申請数</th>
          <?php if (in_array($r['status'], ['pending', 'approved'], true)): ?><th scope="col" class="num">空き</th><?php endif; ?></tr></thead>
        <tbody>
          <?php foreach ($items as $it): ?>
            <tr>
              <td><?= h($it['name']) ?><?= $it['equipment_status'] !== 'available' ? ' <span class="status status-none">利用停止中</span>' : '' ?></td>
              <td><?= h($it['storage_location'] ?? '—') ?></td>
              <td class="num"><?= h($it['quantity']) ?></td>
              <?php if (in_array($r['status'], ['pending', 'approved'], true)): ?>
                <td class="num<?= $it['free'] < $it['quantity'] ? ' is-short' : '' ?>"><?= h($it['free']) ?><?= $it['free'] < $it['quantity'] ? '（不足）' : '' ?></td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

  <?php if ($r['status'] === 'pending'): ?>
    <?php if ($isSelf): ?>
      <p class="alert alert-info">自分の申請は承認・却下できません。別の管理担当者に依頼してください。</p>
    <?php else: ?>
      <div class="action-grid">
        <form method="post" action="<?= h(url($base . '/approve')) ?>" class="action-box">
          <?= csrf_field() ?>
          <h2>承認する</h2>
          <label for="comment">コメント（任意）</label>
          <textarea id="comment" name="comment" rows="2" maxlength="500"></textarea>
          <button type="submit" class="button button-primary">承認</button>
        </form>
        <form method="post" action="<?= h(url($base . '/reject')) ?>" class="action-box">
          <?= csrf_field() ?>
          <h2>却下する</h2>
          <label for="reject-reason">却下の理由（必須・申請者に表示）</label>
          <textarea id="reject-reason" name="reason" rows="2" maxlength="500" required></textarea>
          <button type="submit" class="button button-danger">却下</button>
        </form>
      </div>
    <?php endif; ?>
  <?php elseif ($r['status'] === 'approved'): ?>
    <div class="action-grid">
      <form method="post" action="<?= h(url($base . '/checkout')) ?>" class="action-box">
        <?= csrf_field() ?>
        <h2>貸し出す</h2>
        <?php if ($today < $r['start_date']): ?>
          <p class="help">利用開始日（<?= jp_date($r['start_date']) ?>）から貸し出せます。</p>
        <?php endif; ?>
        <label for="note">メモ（任意）</label>
        <textarea id="note" name="note" rows="2" maxlength="500" placeholder="例：付属品のケーブル2本も渡した"></textarea>
        <button type="submit" class="button button-primary"<?= $today < $r['start_date'] ? ' disabled' : '' ?>>貸出を記録</button>
      </form>
      <form method="post" action="<?= h(url($base . '/cancel')) ?>" class="action-box">
        <?= csrf_field() ?>
        <h2>承認を取り消す</h2>
        <label for="cancel-reason">取消の理由（必須・申請者に表示）</label>
        <textarea id="cancel-reason" name="reason" rows="2" maxlength="500" required></textarea>
        <button type="submit" class="button button-danger">取り消す</button>
      </form>
    </div>
  <?php endif; ?>

  <?php if ($loan !== null): ?>
    <h2>貸出中の備品と返却</h2>
    <form method="post" action="<?= h(url($base . '/return')) ?>">
      <?= csrf_field() ?>
      <div class="table-wrap">
        <table class="data">
          <thead><tr><th scope="col">備品</th><th scope="col" class="num">貸出数</th><th scope="col" class="num">返却済み</th><th scope="col" class="num">未返却</th>
            <?php if ($r['status'] === 'on_loan'): ?><th scope="col" class="num">今回返す数</th><?php endif; ?></tr></thead>
          <tbody>
            <?php foreach ($loanItems as $it): ?>
              <tr>
                <td><label for="ret-<?= h($it['id']) ?>" class="plain-label"><?= h($it['name']) ?></label></td>
                <td class="num"><?= h($it['quantity']) ?></td>
                <td class="num"><?= h($it['returned_quantity']) ?></td>
                <td class="num"><?= h($it['outstanding']) ?></td>
                <?php if ($r['status'] === 'on_loan'): ?>
                  <td class="num">
                    <?php if ($it['outstanding'] > 0): ?>
                      <input id="ret-<?= h($it['id']) ?>" name="return_qty[<?= h($it['id']) ?>]" type="number" class="qty"
                             min="0" max="<?= h($it['outstanding']) ?>" step="1" value="<?= h($it['outstanding']) ?>">
                    <?php else: ?>—<?php endif; ?>
                  </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if ($r['status'] === 'on_loan'): ?>
        <label for="condition_note">状態メモ（任意）</label>
        <textarea id="condition_note" name="condition_note" rows="2" maxlength="500" placeholder="例：マイク1本のスポンジが破れていた"></textarea>
        <button type="submit" class="button button-primary">返却を記録</button>
      <?php endif; ?>
    </form>

    <?php if ($returns !== []): ?>
      <h2>返却の記録</h2>
      <ul class="history">
        <?php foreach ($returns as $rt): ?>
          <li><?= h(App\Support\Clock::toJst((string) $rt['returned_at'])) ?>　<?= h($rt['name']) ?> ×<?= h($rt['quantity']) ?>（<?= h($rt['operator_name']) ?>）<?= ($rt['condition_note'] ?? '') !== '' ? '：' . h($rt['condition_note']) : '' ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  <?php endif; ?>
</section>

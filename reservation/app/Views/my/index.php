<?php
/**
 * 自分の予約・貸出（GET /my/reservations）
 * @var list<array<string,mixed>> $reservations
 * @var string $today
 */
?>
<section class="card">
  <div class="section-head">
    <h1>自分の予約・貸出</h1>
    <a class="button button-small" href="<?= h(url('/reservations/new')) ?>">新しく申請する</a>
  </div>

  <?php if ($reservations === []): ?>
    <p class="empty">まだ予約はありません。「新しく申請する」から備品を予約できます。</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead>
          <tr>
            <th scope="col">状態</th>
            <th scope="col">期間</th>
            <th scope="col">備品</th>
            <th scope="col">申請日</th>
            <th scope="col"><span class="visually-hidden">詳細</span></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($reservations as $r): ?>
            <tr>
              <td><?= status_badge($r['status'], $r['status'] === 'on_loan' && $r['end_date'] < $today) ?></td>
              <td class="nowrap"><?= jp_date($r['start_date']) ?><br>〜<?= jp_date($r['end_date']) ?></td>
              <td><?= h($r['items_summary']) ?></td>
              <td class="nowrap"><?= h(App\Support\Clock::toJst((string) $r['created_at'], 'Y/m/d')) ?></td>
              <td><a href="<?= h(url('/my/reservations/' . $r['id'])) ?>">詳細</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

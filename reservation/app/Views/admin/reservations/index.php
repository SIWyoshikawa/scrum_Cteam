<?php
/**
 * 予約の管理一覧（GET /admin/reservations?tab=）
 * @var array<string,array{0:string,1:list<string>}> $tabs
 * @var string $tab
 * @var list<array<string,mixed>> $reservations
 * @var string $today
 */
?>
<section class="card">
  <h1>予約の管理</h1>

  <nav class="tabs" aria-label="状態で絞り込み">
    <?php foreach ($tabs as $key => [$label]): ?>
      <a href="<?= h(url('/admin/reservations?tab=' . $key)) ?>"<?= $key === $tab ? ' aria-current="page" class="is-current"' : '' ?>><?= h($label) ?></a>
    <?php endforeach; ?>
  </nav>

  <?php if ($reservations === []): ?>
    <p class="empty">「<?= h($tabs[$tab][0]) ?>」の予約はありません。</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead>
          <tr>
            <th scope="col">#</th>
            <th scope="col">状態</th>
            <th scope="col">期間</th>
            <th scope="col">申請者</th>
            <th scope="col">備品</th>
            <th scope="col"><span class="visually-hidden">操作</span></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($reservations as $r): ?>
            <tr>
              <td class="num"><?= h($r['id']) ?></td>
              <td><?= status_badge($r['status'], $r['status'] === 'on_loan' && $r['end_date'] < $today) ?></td>
              <td class="nowrap"><?= jp_date($r['start_date']) ?><br>〜<?= jp_date($r['end_date']) ?></td>
              <td><?= h($r['user_name']) ?><div class="sub"><?= h($r['login_id']) ?></div></td>
              <td><?= h($r['items_summary']) ?></td>
              <td><a class="button button-small" href="<?= h(url('/admin/reservations/' . $r['id'])) ?>">開く</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

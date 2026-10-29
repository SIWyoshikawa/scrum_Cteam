<?php
/**
 * 備品をさがす（GET /items）
 * @var list<array<string,mixed>> $items
 * @var list<array{id:int,name:string}> $categories
 * @var string $q @var ?int $categoryId @var string $start @var string $end
 * @var bool $hasPeriod @var ?string $periodError @var string $today @var bool $canReserve
 */
?>
<section class="card">
  <h1>備品をさがす</h1>

  <form method="get" action="<?= h(url('/items')) ?>" class="search-form">
    <div class="field">
      <label for="q">名前</label>
      <input id="q" name="q" type="text" maxlength="50" value="<?= h($q) ?>" placeholder="例：マイク">
    </div>
    <?php if ($categories !== []): ?>
      <div class="field">
        <label for="category">分類</label>
        <select id="category" name="category">
          <option value="">すべて</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= h($c['id']) ?>"<?= $categoryId === $c['id'] ? ' selected' : '' ?>><?= h($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <div class="field">
      <label for="start">使いたい期間（任意）</label>
      <div class="date-range">
        <input id="start" name="start" type="date" value="<?= h($start) ?>" min="<?= h($today) ?>" aria-label="利用開始日">
        <span aria-hidden="true">〜</span>
        <input id="end" name="end" type="date" value="<?= h($end) ?>" min="<?= h($today) ?>" aria-label="返却予定日">
      </div>
    </div>
    <div class="field field-submit">
      <button type="submit" class="button">検索</button>
    </div>
  </form>

  <?php if ($periodError !== null): ?>
    <p class="alert alert-error" role="alert"><?= h($periodError) ?></p>
  <?php elseif ($hasPeriod): ?>
    <p class="help"><?= jp_date($start) ?>〜<?= jp_date($end) ?> の空き数を表示しています（承認済み・貸出中の分を引いた数）。</p>
  <?php else: ?>
    <p class="help">期間を入れて検索すると、その期間に借りられる数（空き数）が分かります。</p>
  <?php endif; ?>

  <?php if ($items === []): ?>
    <p class="empty">該当する備品はありません。名前を短くするか、分類を「すべて」にして探してください。</p>
  <?php else: ?>
    <div class="table-wrap">
      <table class="data">
        <thead>
          <tr>
            <th scope="col">備品</th>
            <th scope="col">分類</th>
            <th scope="col">保管場所</th>
            <th scope="col" class="num">全数</th>
            <?php if ($hasPeriod): ?><th scope="col" class="num">空き</th><?php endif; ?>
            <?php if ($canReserve): ?><th scope="col"><span class="visually-hidden">操作</span></th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $it): ?>
            <tr>
              <td>
                <strong><?= h($it['name']) ?></strong>
                <?php if (($it['description'] ?? '') !== ''): ?><div class="sub"><?= h($it['description']) ?></div><?php endif; ?>
              </td>
              <td><?= h($it['category_name'] ?? '—') ?></td>
              <td><?= h($it['storage_location'] ?? '—') ?></td>
              <td class="num"><?= h($it['total_quantity']) ?></td>
              <?php if ($hasPeriod): ?>
                <td class="num"><?= $it['free'] > 0 ? h($it['free']) : '<span class="status status-none">空きなし</span>' ?></td>
              <?php endif; ?>
              <?php if ($canReserve): ?>
                <td class="actions-cell">
                  <a class="button button-small" href="<?= h(url('/reservations/new?' . http_build_query(array_filter([
                      'equipment' => $it['id'], 'start' => $hasPeriod ? $start : '', 'end' => $hasPeriod ? $end : '',
                  ])))) ?>">申請する</a>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</section>

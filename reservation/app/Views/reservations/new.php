<?php
/**
 * 予約申請（GET/POST /reservations/new）
 * @var array{start:string,end:string,purpose:string,quantities:array<int,string>} $input
 * @var list<string> $errors
 * @var list<array<string,mixed>> $equipment
 * @var string $today @var string $maxDate
 * @var array<string,int> $rules
 */
?>
<section class="card">
  <h1>予約を申請する</h1>
  <p class="help">
    借りられる期間は最大 <?= h($rules['max_days']) ?> 日（開始日と返却予定日を含む）、利用開始日は
    <?= h($rules['max_advance_days']) ?> 日先まで、進行中の予約は 1人 <?= h($rules['max_active']) ?> 件までです。
  </p>

  <?php if ($errors !== []): ?>
    <div class="alert alert-error" role="alert">
      <ul class="errors">
        <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post" action="<?= h(url('/reservations/new')) ?>" novalidate class="stack-form">
    <?= csrf_field() ?>

    <fieldset>
      <legend>期間</legend>
      <div class="date-range">
        <div>
          <label for="start_date">利用開始日</label>
          <input id="start_date" name="start_date" type="date" required
                 min="<?= h($today) ?>" max="<?= h($maxDate) ?>" value="<?= h($input['start']) ?>">
        </div>
        <span aria-hidden="true" class="range-sep">〜</span>
        <div>
          <label for="end_date">返却予定日</label>
          <input id="end_date" name="end_date" type="date" required
                 min="<?= h($today) ?>" value="<?= h($input['end']) ?>">
        </div>
      </div>
    </fieldset>

    <fieldset>
      <legend>借りたい備品と数</legend>
      <p class="help">借りないものは空欄か 0 のままにします。空き数は <a href="<?= h(url('/items')) ?>">備品をさがす</a> で期間を入れると確認できます。</p>
      <?php if ($equipment === []): ?>
        <p class="empty">いま申請できる備品がありません。</p>
      <?php else: ?>
        <div class="table-wrap">
          <table class="data">
            <thead><tr><th scope="col">備品</th><th scope="col" class="num">全数</th><th scope="col" class="num">借りる数</th></tr></thead>
            <tbody>
              <?php foreach ($equipment as $e): ?>
                <tr>
                  <td><label for="qty-<?= h($e['id']) ?>" class="plain-label"><?= h($e['name']) ?></label></td>
                  <td class="num"><?= h($e['total_quantity']) ?></td>
                  <td class="num">
                    <input id="qty-<?= h($e['id']) ?>" name="qty[<?= h($e['id']) ?>]" type="number" inputmode="numeric"
                           min="0" max="<?= h(min($rules['max_quantity'], $e['total_quantity'])) ?>" step="1" class="qty"
                           value="<?= h($input['quantities'][$e['id']] ?? '') ?>">
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </fieldset>

    <div>
      <label for="purpose">利用目的（必須・500文字以内）</label>
      <textarea id="purpose" name="purpose" rows="3" maxlength="500" required
                placeholder="例：文化祭のステージ発表で使用"><?= h($input['purpose']) ?></textarea>
    </div>

    <div class="actions-row">
      <button type="submit" class="button button-primary">申請する</button>
      <a href="<?= h(url('/')) ?>">やめる</a>
    </div>
  </form>
</section>

<?php
/**
 * 生徒用・教員用ホームの共通部分（student.php / teacher.php から読み込む）
 * @var list<array<string,mixed>> $notifications 未読のお知らせ
 * @var int $activeCount 進行中の予約件数
 * @var int $maxActive
 * @var string $usageNote 「受け取る・使う」の説明
 */
?>
  <?php if ($notifications !== []): ?>
    <section class="card home-section notice-box" aria-labelledby="notice-heading">
      <div class="section-head">
        <h2 id="notice-heading">お知らせ（未読 <?= h(count($notifications)) ?> 件）</h2>
        <form method="post" action="<?= h(url('/notifications/read')) ?>" class="inline">
          <?= csrf_field() ?>
          <button type="submit" class="link-button">すべて既読にする</button>
        </form>
      </div>
      <ul class="notice-list">
        <?php foreach ($notifications as $n): ?>
          <li>
            <span class="notice-date"><?= h(App\Support\Clock::toJst((string) $n['created_at'], 'm/d H:i')) ?></span>
            <?php if ($n['reservation_id'] !== null): ?>
              <a href="<?= h(url('/my/reservations/' . (int) $n['reservation_id'])) ?>"><?= h($n['message']) ?></a>
            <?php else: ?>
              <?= h($n['message']) ?>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>

  <section class="card home-section" aria-labelledby="menu-heading">
    <h2 id="menu-heading">予約・貸出メニュー</h2>
    <ul class="menu-grid">
      <li>
        <a class="menu-item" href="<?= h(url('/items')) ?>">
          <span class="menu-title">備品をさがす</span>
          <span class="menu-desc">名前や分類で探し、期間を入れると空き数が分かります。</span>
        </a>
      </li>
      <li>
        <a class="menu-item" href="<?= h(url('/reservations/new')) ?>">
          <span class="menu-title">予約を申請する</span>
          <span class="menu-desc">期間・利用目的・借りたい数を入力して申請します。</span>
        </a>
      </li>
      <li>
        <a class="menu-item" href="<?= h(url('/my/reservations')) ?>">
          <span class="menu-title">自分の予約・貸出</span>
          <span class="menu-desc">進行中 <?= h($activeCount) ?> 件（最大 <?= h($maxActive) ?> 件）。状況の確認・申請中の取消。</span>
        </a>
      </li>
    </ul>
  </section>

  <section class="card home-section" aria-labelledby="flow-heading">
    <h2 id="flow-heading">利用の流れ</h2>
    <ol class="flow">
      <li><strong>さがす</strong><span>使いたい備品と空き数を確認</span></li>
      <li><strong>申請する</strong><span>期間と利用目的を入力して申請</span></li>
      <li><strong>承認を待つ</strong><span>管理担当者が内容を確認して承認</span></li>
      <li><strong>受け取る・使う</strong><span><?= h($usageNote) ?></span></li>
      <li><strong>返す</strong><span>返却予定日までに窓口へ</span></li>
    </ol>
  </section>

  <p class="help home-foot">30分間操作がないと自動的にログアウトします。パスワードは
    <a href="<?= h(url('/password/change')) ?>">パスワード変更</a> から変えられます。</p>

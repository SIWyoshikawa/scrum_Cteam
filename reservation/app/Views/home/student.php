<?php
/** 生徒用ホーム（GET /、role=student） */
?>
<div class="home home-student">
  <section class="card home-hero">
    <p class="home-role"><span class="badge badge-student">生徒</span> 生徒用画面</p>
    <h1>ようこそ、<?= h($currentUser['name']) ?> さん</h1>
    <p>学校の施設や備品を借りたいときは、ここから予約を申請します。申請は管理担当者が確認してから確定します。</p>
  </section>

  <section class="card home-section" aria-labelledby="menu-heading">
    <h2 id="menu-heading">予約・貸出メニュー</h2>
    <ul class="menu-grid">
      <li class="menu-item is-disabled" aria-disabled="true">
        <span class="menu-title">施設・備品をさがす</span>
        <span class="menu-desc">教室・体育館などの施設や、カメラ・タブレットなどの備品を探します。</span>
        <span class="chip">準備中</span>
      </li>
      <li class="menu-item is-disabled" aria-disabled="true">
        <span class="menu-title">予約を申請する</span>
        <span class="menu-desc">日時と利用目的を入力して申請します。</span>
        <span class="chip">準備中</span>
      </li>
      <li class="menu-item is-disabled" aria-disabled="true">
        <span class="menu-title">自分の予約・貸出</span>
        <span class="menu-desc">申請の状況、受け取り・返却の予定を確認します。</span>
        <span class="chip">準備中</span>
      </li>
    </ul>
  </section>

  <section class="card home-section" aria-labelledby="flow-heading">
    <h2 id="flow-heading">利用の流れ</h2>
    <ol class="flow">
      <li><strong>さがす</strong><span>使いたい施設・備品と空き状況を確認</span></li>
      <li><strong>申請する</strong><span>日時と利用目的を入力して申請</span></li>
      <li><strong>承認を待つ</strong><span>管理担当者が内容を確認して承認</span></li>
      <li><strong>受け取る・使う</strong><span>備品は窓口で受け取り</span></li>
      <li><strong>返す</strong><span>期限までに返却</span></li>
    </ol>
  </section>

  <p class="help home-foot">30分間操作がないと自動的にログアウトします。パスワードは
    <a href="<?= h(url('/password/change')) ?>">パスワード変更</a> から変えられます。</p>
</div>

<?php
/** 生徒用ホーム（GET /、role=student） */
$usageNote = '利用開始日に窓口で受け取り';
?>
<div class="home home-student">
  <section class="card home-hero">
    <p class="home-role"><span class="badge badge-student">生徒</span> 生徒用画面</p>
    <h1>ようこそ、<?= h($currentUser['name']) ?> さん</h1>
    <p>学校の備品を借りたいときは、ここから予約を申請します。申請は管理担当者が確認してから確定します。</p>
  </section>

<?php require __DIR__ . '/_menu.php'; ?>
</div>

<?php
/**
 * 教員用ホーム（GET /、role=teacher）
 * 教員は生徒と同じ一般利用者権限（DB設計書 5-11）。教員だけの操作は置かない。
 */
$usageNote = '利用開始日に窓口で受け取り、授業などで使用';
?>
<div class="home home-teacher">
  <section class="card home-hero">
    <p class="home-role"><span class="badge badge-teacher">教員</span> 教員用画面</p>
    <h1>ようこそ、<?= h($currentUser['name']) ?> 先生</h1>
    <p>授業・部活動・行事で使う備品の予約を、ここから申請します。</p>
    <p class="alert alert-info">教員アカウントも生徒と同じ利用者権限です。申請の承認や貸出・返却の記録は管理担当者が行います。</p>
  </section>

<?php require __DIR__ . '/_menu.php'; ?>
</div>

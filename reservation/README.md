# 学内施設・備品予約貸出管理システム — ログイン・セキュリティ土台

- 詳細設計書 DD-SEC-001 の「11. 実装順 2.」（認証・セッション・CSRF・エラー処理・テンプレート出力の基盤）
- DB は **オーナー作成の `database/migrations/001_equipment_management.sql`**（データベース名 `equipment_management`）を土台にする。
  このファイルは変更せず、足りないログイン試行の記録だけを `002_login_attempts.sql` で追加している。
- ログインまわりの考え方は PO 作成の **ログイン認証DB設計書**（`docs/auth_database_design.md`）に準拠

## できていること

| 機能 | 根拠 | 主な場所 |
|---|---|---|
| ログイン／ログアウト（`password_hash` / `password_verify`、ID存在を明かさない） | DB設計 5-2 | `app/Services/AuthService.php` |
| 15分で5回失敗 → 15分ロック、同一IP 15分20回で一時拒否 | DB設計 5-8 | `app/Services/LoginThrottle.php` |
| ログイン時にセッションID再生成、SHA-256 を `user_sessions` に登録 | DB設計 5-3 | `AuthService::establishSession` |
| 毎リクエストで「行あり・未失効・active・期限内」を確認、操作で `last_active_at` 更新・`expires_at` を30分先へ延長 | DB設計 5-4 | `app/Support/App.php` |
| 複数端末ログイン可。ログアウトはその端末だけ失効（行は削除せず `revoked_at`） | DB-009 | `SessionRepository` |
| 初回・仮PW再発行後はパスワード変更以外を使えない | DB設計 5-5 | `AuthMiddleware`, `PasswordController` |
| 停止／再有効化／仮PW再発行（全端末失効・監査をトランザクションで） | DB設計 5-6, 5-7 | `app/Services/AccountService.php`, `scripts/user_admin.php` |
| 教員は一般利用者と同じ扱い（`role:user`）、管理画面は `role:admin` のみ | DB設計 5-11 | `RoleMiddleware` |
| ホーム（`/`）を生徒用・教員用に分けて表示（権限は同じ、案内文だけ違う）。管理者は `/admin` へ | DB設計 5-11 | `HomeController`, `app/Views/home/student.php`, `teacher.php` |
| 監査ログ `audit_logs`（`entity_type='user'`、成否とIPは `details` にJSON）、アプリDBユーザーに更新・削除権限なし | DB-012 | `AuthAuditLogger` |
| 画面に名前（`users.name`）とユーザーIDを表示 | — | `app/Views/layout.php` |
| 保存はUTC、表示はJST | DB-015 | `app/Support/Clock.php` |
| CSRF（全POST）、XSS（`h()`＋CSP）、SQLi（本物のプリペアド）、安全なエラー画面 | DD 6.1〜6.5 | `app/Support/*` |
| `public/` 以外をWebから見せない | DD 2.1 | `.htaccess` |

まだ無いもの：備品一覧・予約・承認・貸出・返却の画面、管理画面からのユーザー登録（当面はコマンド）。
テーブル（`equipment` / `reservations` / `loans` など）はオーナーのSQLで作成済み。

---

## セットアップ（Mac の XAMPP）

### 1. フォルダを置く

`/Applications/XAMPP/xamppfiles/htdocs/reservation/` に中身を丸ごと置く（`.htaccess` などの隠しファイルも。Finderでは Cmd+Shift+. で表示）。

### 2. データベースを作る（phpMyAdmin の「インポート」）

1. `database/migrations/001_equipment_management.sql`（オーナー作成）をインポート
   → 左に `equipment_management` ができ、テーブル12個と備品5件（梯子・マイクなど）が入る
2. 続けて `database/migrations/002_login_attempts.sql` をインポート → `login_attempts` が増えて13個

> 前の版で作った `school_reservation` データベースはもう使わない。phpMyAdmin で削除してよい。

### 3. アプリ用のDBユーザーと `.env` を作る

```bash
cd /Applications/XAMPP/xamppfiles/htdocs/reservation
/Applications/XAMPP/xamppfiles/bin/php scripts/setup_db_user.php
```

DBの管理者(root)のパスワードを聞かれる（XAMPPの初期状態なら何も入力せず Enter）。
`rsv_app` ユーザーの作成・権限付与・`.env` の `DB_PASSWORD` 書き換えまで自動で行う。

**前の版の `.env` がある場合は、先に `DB_NAME=equipment_management` に書き換えておく。**

### 4. ログフォルダに書き込めるようにする

```bash
sudo chown -R daemon:staff storage/logs
sudo chmod -R 770 storage/logs
```

### 5. 利用者を登録する（架空データのみ）

```bash
PHP=/Applications/XAMPP/xamppfiles/bin/php
$PHP scripts/create_user.php --user-id=admin01 --name="管理 太郎" --email=admin01@example.com --role=admin
$PHP scripts/create_user.php --user-id=s0001   --name="生徒 花子" --email=s0001@example.com   --role=student
$PHP scripts/create_user.php --user-id=t0001   --name="教員 一郎" --email=t0001@example.com   --role=teacher
```

名前（`--name`）は必須。架空の名前にする。仮パスワードが1回だけ表示される。初回ログインでパスワード変更画面になる。

### 6. 開く

http://localhost/reservation/login

### 7. 守りが効いているか確認

次を開いて **403 か 404 になればOK**（中身が見えたら危険）：

- http://localhost/reservation/.env
- http://localhost/reservation/config/app.php
- http://localhost/reservation/storage/logs/

---

## アカウント管理（管理画面ができるまでの代わり）

```bash
$PHP scripts/user_admin.php suspend        --user-id=s0001   # 停止＋全端末ログアウト
$PHP scripts/user_admin.php reactivate     --user-id=s0001   # 再有効化（古いログインは復活しない）
$PHP scripts/user_admin.php reset-password --user-id=s0001   # 仮パスワード再発行＋全端末ログアウト
```

## テスト

```bash
# 単体テスト（DB不要）
$PHP tests/run.php

# 動いているサーバに対するセキュリティ確認（71項目）
# smoke_ で始まる架空ユーザーを自動で作り、最後に停止状態にする
BASE_URL=http://localhost/reservation $PHP tests/Security/smoke_test.php
```

---

## 今後機能を足すときのルール

1. ルートは `app/routes.php` に明示登録。生徒・教員用は `['auth', 'role:user']`、管理者用は `['auth', 'role:admin']`。
2. 状態を変える操作は POST だけ。フォームに `<?= csrf_field() ?>`。
3. 画面に出す値は必ず `<?= h($value) ?>`。インラインの `style` / `onclick` は CSP でブロックされる。
4. SQL は `prepare` ＋ `:名前` バインドだけ。
5. 他人のデータは `WHERE user_id = ログイン中の users.id` で絞る。
   **注意：`users.user_id` はログインID（文字列）、外部キーが指すのは `users.id`（数値）。** アプリ内では
   `$app->user['id']`（数値）と `$app->user['login_id']`（文字列）で区別している。
6. DBに入れる時刻は UTC（`Clock::nowUtc()` / `UTC_TIMESTAMP()`）、画面には `Clock::toJst()`。
   接続時に `time_zone = '+00:00'` にしているので、オーナーのテーブルの `DEFAULT CURRENT_TIMESTAMP` もUTCで入る。
7. 新しいテーブルを作ったら、`02_create_app_user.sql` に必要最小限の権限を追記。

## PO確認待ち

- オーナーのSQLには**施設**のテーブルが無く、備品（`equipment`）だけ → 施設の予約は対象外になったのか
- オーナーのSQLの注意書きにある業務ルール（予約7日以内／90日先まで／最大5件／自己承認禁止／延滞中は利用禁止）の正確な意味
- `notifications` はアプリ内のお知らせ一覧として使う想定（要望書の対象外「リアルタイム通知」には当たらない）でよいか
- パスワード変更日時の列が `users` に無いので、`audit_logs` の `password_changed` で代わりに記録している
- `users.user_id` と `user_sessions.user_id` が同じ名前で別の意味
- 本人がパスワードを変えたとき、**他の端末も全部ログアウト**する実装にした（設計書に明記なし。漏えい対策として）
- オーナーのSQLの先頭に `SET NAMES utf8mb4;` を足すとよい（phpMyAdmin以外のツールで流すと「梯子」などが文字化けするため）

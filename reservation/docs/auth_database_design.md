# 学内施設・備品予約貸出管理システム：ログイン認証DB設計書

## 1. 範囲

PHP + MySQL/MariaDB (XAMPP) を用いたログイン・アカウント・権限・セッション管理のDB設計。
予約・貸出・返却など業務テーブルは今回対象外。将来 `reservations.user_id` 等の外部キーから `users.id` を参照する。

## 2. 決定記録

| ID | 決定 | 状態 |
|---|---|---|
| DB-001 | `users.id` は自動採番、`users.user_id` は管理者設定でUNIQUE | 既決 |
| DB-002 | `users.email` は管理者登録時必須・UNIQUE | 既決 |
| DB-003 | パスワードはPHP `password_hash()` でハッシュ化、`password_verify()` で検証 | 既決 |
| DB-004 | 権限は `roles` と `users.role_id` で管理 | 既決 |
| DB-005 | `users.status` で `active` / `suspended` を表現 | 既決 |
| DB-006 | `users.must_change_password` で初回・再発行後の変更を強制 | 既決 |
| DB-007 | `user_sessions` を作成し、端末ごとにセッションを管理 | 推奨採用 |
| DB-008 | 30分無操作でセッションを期限切れ扱いにする | PO仕様の実装方式 |
| DB-009 | 複数端末ログインを許可 | PO仕様の実装方式 |
| DB-010 | 停止時に全セッションを失効し、履歴は残す | PO仕様の実装方式 |
| DB-011 | 管理者が仮PW再発行時にハッシュを更新、変更必須に戻し全セッション失効 | PO仕様の実装方式 |
| DB-012 | `auth_audit_logs` に認証・管理操作を記録 | 推奨採用 |
| DB-013 | `login_attempts` で試行履歴を記録し、アプリでレート制限 | 推奨採用 |
| DB-014 | ユーザーは物理削除せず、論理停止で過去の監査・予約履歴を維持 | PO仕様の実装方式 |
| DB-015 | 保存時刻はUTC、画面表示はJST | 推奨採用 |

## 3. テーブル定義

### roles
- `id`: BIGINT UNSIGNED、主キー、自動採番
- `role_name`: VARCHAR(50)、必須、一意。値: student / teacher / admin
- `display_name`: VARCHAR(50)、必須

### users
- `id`: BIGINT UNSIGNED、主キー、自動採番
- `user_id`: VARCHAR(50)、必須、一意。ログインで使用
- `password_hash`: VARCHAR(255)、必須。平文保存禁止
- `email`: VARCHAR(255)、必須、一意。ログイン照合には使用しない
- `role_id`: BIGINT UNSIGNED、必須、`roles.id`への外部キー
- `status`: VARCHAR(20)、必須、既定active。active / suspended のみ
- `must_change_password`: BOOLEAN、必須、既定TRUE
- `password_changed_at`: DATETIME(6)、NULL可。最後の本人PW変更日時（初回登録時はNULL）
- `created_at` / `updated_at`: DATETIME(6)、必須

### user_sessions
- `id`: BIGINT UNSIGNED、主キー、自動採番
- `user_id`: BIGINT UNSIGNED、必須、`users.id`への外部キー
- `session_token_hash`: CHAR(64) ASCIIバイナリ照合、必須、一意。高エントロピーPHPセッションIDのSHA-256
- `created_at`: DATETIME(6)、必須
- `last_activity_at`: DATETIME(6)、必須
- `revoked_at`: DATETIME(6)、NULL可（NULL: 未失効）

### auth_audit_logs
- `id`: BIGINT UNSIGNED、主キー、自動採番
- `actor_user_id`: BIGINT UNSIGNED、NULL可、操作主体（ユーザー不明の失敗はNULL）
- `target_user_id`: BIGINT UNSIGNED、NULL可、対象ユーザー
- `action`: VARCHAR(50)、必須。例: login_success / login_failure / logout / password_changed / password_reset_by_admin / account_suspended / account_reactivated
- `result`: VARCHAR(20)、必須。success / failure
- `ip_address`: VARBINARY(16)、NULL可。PHP inet_ptonで変換（個人情報として適切に管理）
- `occurred_at`: DATETIME(6)、必須

### login_attempts
- `id`: BIGINT UNSIGNED、主キー、自動採番
- `user_id_input`: VARCHAR(50)、必須。ログイン画面の入力値（存在しないIDも記録可）
- `ip_address`: VARBINARY(16)、NULL可
- `succeeded`: BOOLEAN、必須
- `attempted_at`: DATETIME(6)、必須

## 4. 関連
- `roles (1)` → `users (N)`
- `users (1)` → `user_sessions (N)`
- `users (1)` → `auth_audit_logs (N)` ただし監査の主体・対象はNULL可
- `login_attempts` は未知のユーザーIDを含むため、`users`との外部キーを設けない
- 全外部キーは削除・更新をRESTRICTし、ユーザー情報と監査履歴の整合を維持

## 5. 必須アプリケーション処理

1. 管理者だけが利用者アカウントを事前登録する。`user_id`、`email`は重複不可とし、`role_id`は管理者が設定。登録PWは仮パスワードをハッシュ化し、`must_change_password=TRUE`。
2. ログイン時はPDOプリペアドステートメントで`user_id`を検索し、`password_verify()`と`status='active'`を検証。エラー文はID存在有無を漏らさない。
3. 認証成功時は`session_regenerate_id(true)`などでセッション固定化を防ぎ、新セッションIDのSHA-256を `user_sessions` に登録。CookieはSecure・HttpOnly・SameSiteを適切に指定し、HTTPSを必須にする。
4. 認証済みの全リクエストでDBセッション行の存在、`revoked_at IS NULL`、`users.status='active'`、`last_activity_at > UTC_TIMESTAMP(6) - INTERVAL 30 MINUTE`を確認する。無効ならPHPセッションも破棄。活動した場合に`last_activity_at`を更新する。**SQLのテーブル定義だけではタイムアウトは発動しない**。
5. `must_change_password=TRUE` の間はパスワード変更・ログアウトなどの最小限の機能のみ許可する。新しいハッシュ保存、`must_change_password=FALSE`、`password_changed_at`更新を同一トランザクションに入れる。パスワード変更後はセッションIDを再生成する。
6. 管理者がアカウントを停止する場合、`users.status='suspended'` と `user_sessions.revoked_at=UTC_TIMESTAMP(6)` を同一DBトランザクションで更新し監査ログを記録する。再有効化では元のセッションを復活させない。
7. 管理者が仮パスワードを再発行する場合、本人確認・管理者認可後、PWハッシュ更新、`must_change_password=TRUE`、全セッション失効、監査ログ記録を同一トランザクションで行う。仮PWの平文をログに残さない。
8. ログイン試行は`login_attempts`に記録し、例として同一ユーザーIDで15分あたり失敗5回、同一IPで15分あたり失敗20回を超えたら一定期間遅延・制限する。値は試験運用で調整し、アカウント列挙・サービス妨害に注意。
9. `login_attempts`は短期保存（例: 90日）とし、監査ログは学校の保管方針を確認するまで自動削除しない。個人情報アクセスは管理者に限定する。
10. 各SQL接続は `SET time_zone = '+00:00'` とし、PHPもUTCで保存し、日本時間表示・日付業務ルールは `Asia/Tokyo` で処理する。
11. `teacher`は分類上は存在するが、新たな教員専用の管理者操作は付与しない。アプリのアクセス制御ではstudentと同じ通常利用者権限として扱う（別途PO変更まで）。
12. 単一DB接続ユーザーは最小権限とし、CSRF/XSS対策、認可検証、ログイン処理の競合・例外処理を実装する。

## 6. 未実装・設計範囲外

- このSQLは**DB構造の作成用**で、PHPの認証処理、画面、API、タイムアウトジョブを実装するものではない。
- 管理者初期アカウントはSQLに固定パスワードを埋め込まず、安全な初期化スクリプトから作成する。
- 施設・備品・予約・貸出・返却の業務テーブルは対象外。
- セッション情報の定期削除、IPアドレスの保管期限、監査ログ保存年限は運用規程に沿い別途実装・確認する。
- CSV等から移行する場合、重複メールやNULLメールの事前整理が必要。

## 7. 導入

1. バックアップを取得する。
2. phpMyAdminのSQLタブで`auth_schema.sql`を実行する。
3. `school_reservation`データベース内に5テーブルと3つのロールが作られたことを確認する。
4. PHP側で管理者用の初期ユーザー作成機能と認証処理を実装する。

実DBへの適用は、既存テーブル・DB名・権限を確認してから行うこと。

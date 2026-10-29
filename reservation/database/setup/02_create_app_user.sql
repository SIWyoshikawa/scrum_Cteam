-- アプリ専用のDBユーザーを作る（root など管理者ユーザーで、migrations の 001・002 の「あと」に実行）
-- アプリは root ではなく、必要最小限の権限だけを持つこのユーザーで接続する（DD 6.1 / DB設計書 5-12）
--
-- おすすめ：手で実行せず scripts/setup_db_user.php を使う（パスワード作成と .env 書き換えを自動で行う）
-- 手で実行する場合は、phpMyAdmin に貼り付けたあと、入力欄の中で
-- 'CHANGE_ME_TO_LONG_RANDOM_PASSWORD'（4か所）を同じ長いパスワードに置き換えてから実行する
-- そのパスワードは .env の DB_PASSWORD にだけ書き、Git にはコミットしない

CREATE USER IF NOT EXISTS 'rsv_app'@'localhost' IDENTIFIED BY 'CHANGE_ME_TO_LONG_RANDOM_PASSWORD';
CREATE USER IF NOT EXISTS 'rsv_app'@'127.0.0.1' IDENTIFIED BY 'CHANGE_ME_TO_LONG_RANDOM_PASSWORD';
-- すでに rsv_app が居た場合（やり直し時など）も、パスワードを確実に上書きする
ALTER USER 'rsv_app'@'localhost' IDENTIFIED BY 'CHANGE_ME_TO_LONG_RANDOM_PASSWORD';
ALTER USER 'rsv_app'@'127.0.0.1' IDENTIFIED BY 'CHANGE_ME_TO_LONG_RANDOM_PASSWORD';

-- ---- 認証まわり ----
-- roles：参照のみ（権限の種類はアプリから変えない）
GRANT SELECT                 ON equipment_management.roles                TO 'rsv_app'@'localhost', 'rsv_app'@'127.0.0.1';
-- users：参照・登録・更新（削除はしない。停止は status を suspended に）
GRANT SELECT, INSERT, UPDATE ON equipment_management.users                TO 'rsv_app'@'localhost', 'rsv_app'@'127.0.0.1';
-- user_sessions：参照・登録・更新（失効は revoked_at の更新。削除はしない）
GRANT SELECT, INSERT, UPDATE ON equipment_management.user_sessions        TO 'rsv_app'@'localhost', 'rsv_app'@'127.0.0.1';
-- audit_logs：参照・登録のみ（UPDATE / DELETE を与えない＝アプリから改ざん・削除できない）
GRANT SELECT, INSERT         ON equipment_management.audit_logs           TO 'rsv_app'@'localhost', 'rsv_app'@'127.0.0.1';
-- login_attempts：参照・登録のみ（古い行の削除は、別の管理用ユーザーで運用として行う）
GRANT SELECT, INSERT         ON equipment_management.login_attempts       TO 'rsv_app'@'localhost', 'rsv_app'@'127.0.0.1';

-- ---- 備品・予約・貸出（画面はこれから作る。物理削除はしないので DELETE は与えない） ----
GRANT SELECT, INSERT, UPDATE ON equipment_management.equipment_categories TO 'rsv_app'@'localhost', 'rsv_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON equipment_management.equipment            TO 'rsv_app'@'localhost', 'rsv_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON equipment_management.reservations         TO 'rsv_app'@'localhost', 'rsv_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON equipment_management.reservation_items    TO 'rsv_app'@'localhost', 'rsv_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON equipment_management.loans                TO 'rsv_app'@'localhost', 'rsv_app'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE ON equipment_management.loan_items           TO 'rsv_app'@'localhost', 'rsv_app'@'127.0.0.1';
-- returns：返却の記録は追記のみ
GRANT SELECT, INSERT         ON equipment_management.returns              TO 'rsv_app'@'localhost', 'rsv_app'@'127.0.0.1';
-- notifications：既読にするため UPDATE あり
GRANT SELECT, INSERT, UPDATE ON equipment_management.notifications        TO 'rsv_app'@'localhost', 'rsv_app'@'127.0.0.1';

FLUSH PRIVILEGES;

-- テーブルを追加したら、必要な権限だけをここに追記していく

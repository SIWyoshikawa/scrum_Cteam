-- アプリ専用のDBユーザーを作る（root など管理者ユーザーで、auth_schema.sql の「あと」に実行）
-- アプリは root ではなく、必要最小限の権限だけを持つこのユーザーで接続する（DD 6.1 / DB設計書 5-12）
--
-- !!! phpMyAdmin に貼り付けたあと、入力欄の中で 'CHANGE_ME_TO_LONG_RANDOM_PASSWORD'（2か所）を
-- !!! 自分で決めた長いパスワードに置き換えてから実行する（このファイル自体は書き換えない）
-- !!! そのパスワードは .env の DB_PASSWORD にだけ書き、Git にはコミットしない

CREATE USER IF NOT EXISTS 'rsv_app'@'localhost' IDENTIFIED BY 'CHANGE_ME_TO_LONG_RANDOM_PASSWORD';
CREATE USER IF NOT EXISTS 'rsv_app'@'127.0.0.1' IDENTIFIED BY 'CHANGE_ME_TO_LONG_RANDOM_PASSWORD';

-- roles：参照のみ（権限の種類はアプリから変えない）
GRANT SELECT                 ON school_reservation.roles           TO 'rsv_app'@'localhost', 'rsv_app'@'127.0.0.1';
-- users：参照・登録・更新（削除はしない。DB-014 論理停止）
GRANT SELECT, INSERT, UPDATE ON school_reservation.users           TO 'rsv_app'@'localhost', 'rsv_app'@'127.0.0.1';
-- user_sessions：参照・登録・更新（失効は revoked_at の更新。削除はしない）
GRANT SELECT, INSERT, UPDATE ON school_reservation.user_sessions   TO 'rsv_app'@'localhost', 'rsv_app'@'127.0.0.1';
-- auth_audit_logs：参照・登録のみ（UPDATE / DELETE を与えない＝アプリから改ざん・削除できない）
GRANT SELECT, INSERT         ON school_reservation.auth_audit_logs TO 'rsv_app'@'localhost', 'rsv_app'@'127.0.0.1';
-- login_attempts：参照・登録のみ（90日などの期限削除は、別の管理用ユーザーで運用として行う）
GRANT SELECT, INSERT         ON school_reservation.login_attempts  TO 'rsv_app'@'localhost', 'rsv_app'@'127.0.0.1';

FLUSH PRIVILEGES;

-- 今後テーブルを追加したら（items, reservations など）、必要な権限だけをここに追記していく

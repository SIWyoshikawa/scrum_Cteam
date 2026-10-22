-- 学内施設・備品予約貸出管理システム
-- 認証・ユーザー管理DB / MySQL 8.0+・MariaDB 10.6+ 想定
-- 時刻はUTCで保存し、表示時にAsia/Tokyoへ変換する。
-- アプリ側はPDOプリペアドステートメント、password_hash / password_verify を使用する。

CREATE DATABASE IF NOT EXISTS school_reservation
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE school_reservation;

-- 1. 権限マスター: 既存のPO決定により teacher の操作範囲は一般利用者相当
CREATE TABLE IF NOT EXISTS roles (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  role_name VARCHAR(50) NOT NULL,
  display_name VARCHAR(50) NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_roles_name (role_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO roles (role_name, display_name) VALUES
  ('student', '生徒'),
  ('teacher', '教員'),
  ('admin', '管理者')
ON DUPLICATE KEY UPDATE role_name = VALUES(role_name);

-- 2. ユーザー: 管理者が登録し、user_id / email は重複禁止
-- password_hashにはPHP password_hash()の戻り値のみを格納する。
CREATE TABLE IF NOT EXISTS users (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id VARCHAR(50) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  email VARCHAR(255) NOT NULL,
  role_id BIGINT UNSIGNED NOT NULL,
  status VARCHAR(20) NOT NULL DEFAULT 'active',
  must_change_password BOOLEAN NOT NULL DEFAULT TRUE,
  password_changed_at DATETIME(6) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_user_id (user_id),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_role_id (role_id),
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT chk_users_status CHECK (status IN ('active', 'suspended'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. セッション: PHP標準セッションIDをSHA-256でハッシュ化した値のみ記録。
-- 各リクエストで revoked_at IS NULL、users.status、
-- last_activity_at > UTC_TIMESTAMP(6) - INTERVAL 30 MINUTE を必ず検証。
-- 管理者による停止や仮PW再発行では対象ユーザーの全行を失効させる。
CREATE TABLE IF NOT EXISTS user_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NOT NULL,
  session_token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  last_activity_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  revoked_at DATETIME(6) NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_sessions_token_hash (session_token_hash),
  KEY idx_sessions_user_active (user_id, revoked_at),
  KEY idx_sessions_last_activity (last_activity_at),
  CONSTRAINT fk_sessions_user FOREIGN KEY (user_id) REFERENCES users (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. 監査ログ: 成否・実行主体・対象・時間を記録（PWやセッショントークンは記録しない）。
CREATE TABLE IF NOT EXISTS auth_audit_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  actor_user_id BIGINT UNSIGNED NULL,
  target_user_id BIGINT UNSIGNED NULL,
  action VARCHAR(50) NOT NULL,
  result VARCHAR(20) NOT NULL,
  ip_address VARBINARY(16) NULL,
  occurred_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_audit_actor_time (actor_user_id, occurred_at),
  KEY idx_audit_target_time (target_user_id, occurred_at),
  KEY idx_audit_action_time (action, occurred_at),
  CONSTRAINT fk_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT fk_audit_target FOREIGN KEY (target_user_id) REFERENCES users (id)
    ON UPDATE RESTRICT ON DELETE RESTRICT,
  CONSTRAINT chk_audit_result CHECK (result IN ('success', 'failure'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. ログイン試行履歴: 存在しないユーザーIDへの失敗も記録できる。
-- user_id_input / IPに基づく短期レート制限用。保存期限は運用で設定。
CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id_input VARCHAR(50) NOT NULL,
  ip_address VARBINARY(16) NULL,
  succeeded BOOLEAN NOT NULL,
  attempted_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  PRIMARY KEY (id),
  KEY idx_attempts_user_time (user_id_input, attempted_at),
  KEY idx_attempts_ip_time (ip_address, attempted_at),
  KEY idx_attempts_time (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

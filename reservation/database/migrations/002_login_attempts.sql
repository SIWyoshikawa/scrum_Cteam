-- 001_equipment_management.sql（オーナー作成）の「あと」に実行する追加分
-- オーナーのテーブルは変更せず、ログイン試行の記録テーブルだけを足す。
--
-- 理由：同じIDで15分に5回失敗 → 15分ロック、同じIPから15分に20回失敗 → 一時拒否
--       （DB設計書 5-8 / 詳細設計書 6.3）を行うには、失敗の記録が必要。
--       存在しないユーザーIDでの失敗も数えるため、users への外部キーは付けない。
SET NAMES utf8mb4;
USE equipment_management;

CREATE TABLE IF NOT EXISTS login_attempts (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id_input VARCHAR(50) NOT NULL,
 ip_address VARBINARY(16) NULL,
 succeeded BOOLEAN NOT NULL,
 attempted_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
 INDEX idx_attempts_user_time (user_id_input, attempted_at),
 INDEX idx_attempts_ip_time (ip_address, attempted_at),
 INDEX idx_attempts_time (attempted_at)
) ENGINE=InnoDB;

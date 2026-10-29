-- 備品貸出管理システム / XAMPP MariaDB向け
-- phpMyAdminの「インポート」から実行可能
CREATE DATABASE IF NOT EXISTS equipment_management CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE equipment_management;

CREATE TABLE IF NOT EXISTS roles (
 id TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 role_name VARCHAR(30) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS users (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id VARCHAR(50) NOT NULL UNIQUE,
 name VARCHAR(100) NOT NULL,
 email VARCHAR(255) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 role_id TINYINT UNSIGNED NOT NULL,
 status ENUM('active','suspended') NOT NULL DEFAULT 'active',
 must_change_password BOOLEAN NOT NULL DEFAULT TRUE,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY (role_id) REFERENCES roles(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS equipment_categories (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(100) NOT NULL UNIQUE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS equipment (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(100) NOT NULL,
 total_quantity INT UNSIGNED NOT NULL DEFAULT 0,
 category_id INT UNSIGNED NULL,
 description TEXT NULL,
 storage_location VARCHAR(100) NULL,
 status ENUM('available','inactive') NOT NULL DEFAULT 'available',
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY (category_id) REFERENCES equipment_categories(id),
 INDEX idx_equipment_name (name)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reservations (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 start_date DATE NOT NULL,
 end_date DATE NOT NULL,
 purpose VARCHAR(500) NOT NULL,
 status ENUM('pending','approved','rejected','cancelled','expired','on_loan','completed') NOT NULL DEFAULT 'pending',
 reviewed_by BIGINT UNSIGNED NULL,
 reviewed_at DATETIME NULL,
 review_reason VARCHAR(500) NULL,
 cancelled_by BIGINT UNSIGNED NULL,
 cancellation_reason VARCHAR(500) NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 FOREIGN KEY (user_id) REFERENCES users(id),
 FOREIGN KEY (reviewed_by) REFERENCES users(id),
 FOREIGN KEY (cancelled_by) REFERENCES users(id),
 INDEX idx_reservation_user_status (user_id,status),
 INDEX idx_reservation_dates_status (start_date,end_date,status),
 CONSTRAINT chk_reservation_dates CHECK (end_date >= start_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS reservation_items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 reservation_id BIGINT UNSIGNED NOT NULL,
 equipment_id INT UNSIGNED NOT NULL,
 quantity INT UNSIGNED NOT NULL,
 FOREIGN KEY (reservation_id) REFERENCES reservations(id),
 FOREIGN KEY (equipment_id) REFERENCES equipment(id),
 UNIQUE KEY uq_reservation_equipment (reservation_id,equipment_id),
 INDEX idx_res_item_equipment (equipment_id),
 CONSTRAINT chk_reservation_quantity CHECK (quantity > 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS loans (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 reservation_id BIGINT UNSIGNED NOT NULL UNIQUE,
 handed_out_by BIGINT UNSIGNED NOT NULL,
 lent_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 due_date DATE NOT NULL,
 status ENUM('on_loan','returned') NOT NULL DEFAULT 'on_loan',
 note VARCHAR(500) NULL,
 FOREIGN KEY (reservation_id) REFERENCES reservations(id),
 FOREIGN KEY (handed_out_by) REFERENCES users(id),
 INDEX idx_loans_status_due (status,due_date)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS loan_items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 loan_id BIGINT UNSIGNED NOT NULL,
 equipment_id INT UNSIGNED NOT NULL,
 quantity INT UNSIGNED NOT NULL,
 returned_quantity INT UNSIGNED NOT NULL DEFAULT 0,
 FOREIGN KEY (loan_id) REFERENCES loans(id),
 FOREIGN KEY (equipment_id) REFERENCES equipment(id),
 UNIQUE KEY uq_loan_equipment (loan_id,equipment_id),
 CONSTRAINT chk_loan_quantity CHECK (quantity > 0),
 CONSTRAINT chk_returned_quantity CHECK (returned_quantity <= quantity)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS returns (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 loan_item_id BIGINT UNSIGNED NOT NULL,
 quantity INT UNSIGNED NOT NULL,
 returned_by BIGINT UNSIGNED NOT NULL,
 returned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 condition_note VARCHAR(500) NULL,
 FOREIGN KEY (loan_item_id) REFERENCES loan_items(id),
 FOREIGN KEY (returned_by) REFERENCES users(id),
 CONSTRAINT chk_return_quantity CHECK (quantity > 0)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS notifications (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 reservation_id BIGINT UNSIGNED NULL,
 type VARCHAR(40) NOT NULL,
 message VARCHAR(500) NOT NULL,
 is_read BOOLEAN NOT NULL DEFAULT FALSE,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 read_at DATETIME NULL,
 FOREIGN KEY (user_id) REFERENCES users(id),
 FOREIGN KEY (reservation_id) REFERENCES reservations(id),
 INDEX idx_notifications_user (user_id,is_read,created_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS user_sessions (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id BIGINT UNSIGNED NOT NULL,
 session_token_hash CHAR(64) NOT NULL UNIQUE,
 last_active_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 expires_at DATETIME NOT NULL,
 revoked_at DATETIME NULL,
 FOREIGN KEY (user_id) REFERENCES users(id),
 INDEX idx_sessions_user (user_id,revoked_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 actor_user_id BIGINT UNSIGNED NULL,
 action VARCHAR(80) NOT NULL,
 entity_type VARCHAR(40) NOT NULL,
 entity_id BIGINT UNSIGNED NULL,
 details TEXT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 FOREIGN KEY (actor_user_id) REFERENCES users(id),
 INDEX idx_audit_entity (entity_type,entity_id),
 INDEX idx_audit_created (created_at)
) ENGINE=InnoDB;

INSERT IGNORE INTO roles (id,role_name) VALUES (1,'student'),(2,'teacher'),(3,'admin');
INSERT IGNORE INTO equipment (id,name,total_quantity,status) VALUES
 (1,'梯子',2,'available'),(2,'マイク',10,'available'),(3,'ノートPC',5,'available'),
 (4,'電子黒板',1,'available'),(5,'モニター',10,'available');

-- 注意: 以下の業務制約はPHP側でトランザクションとロックを使って検証すること。
-- 予約7日以内/90日先/最大5件/本人キャンセル条件/自己承認禁止/延滞利用禁止。
-- 期間が重なる予約の数量を集計し、equipment行をSELECT ... FOR UPDATEでロックしてから確定する。
-- 返却時はreturns追加・loan_items.returned_quantity更新・完了判定を同一トランザクション内で実行する。
-- 個体ごとの製造番号や故障管理が必要ならequipment_unitsテーブルを別途追加する。

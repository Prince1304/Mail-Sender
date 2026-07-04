CREATE DATABASE IF NOT EXISTS `bulk_mail_sender`
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE `bulk_mail_sender`;

CREATE TABLE IF NOT EXISTS `customers` (
    `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(120) NOT NULL,
    `email` VARCHAR(180) NOT NULL UNIQUE,
    `phone` VARCHAR(30) DEFAULT '',
    `company` VARCHAR(150) DEFAULT '',
    `group_tag` VARCHAR(80) DEFAULT 'General',
    `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_customers_group` (`group_tag`),
    INDEX `idx_customers_status` (`status`),
    INDEX `idx_customers_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `email_logs` (
    `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `customer_id` INT UNSIGNED NULL,
    `recipient_name` VARCHAR(120) NOT NULL,
    `recipient_email` VARCHAR(180) NOT NULL,
    `email_subject` VARCHAR(255) NOT NULL,
    `email_body` MEDIUMTEXT NOT NULL,
    `send_mode` ENUM('all', 'group', 'selected') NOT NULL DEFAULT 'all',
    `group_tag` VARCHAR(80) DEFAULT NULL,
    `is_html` TINYINT(1) NOT NULL DEFAULT 1,
    `status` ENUM('sent', 'failed') NOT NULL,
    `error_message` TEXT DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_email_logs_customer` (`customer_id`),
    INDEX `idx_email_logs_status` (`status`),
    INDEX `idx_email_logs_created` (`created_at`),
    CONSTRAINT `fk_email_logs_customer`
        FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

<?php
declare(strict_types=1);

define('DB_HOST', 'localhost');
define('DB_PORT', 3306);
define('DB_NAME', 'task_manager');
define('DB_USER', 'root');
define('DB_PASS', '');

function getDB(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    $serverDsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';charset=utf8mb4';
    $serverPdo = new PDO($serverDsn, DB_USER, DB_PASS, $options);
    $serverPdo->exec(
        "CREATE DATABASE IF NOT EXISTS `" . DB_NAME . "` " .
        "CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    );

    $databaseDsn = $serverDsn . ';dbname=' . DB_NAME;
    $pdo = new PDO($databaseDsn, DB_USER, DB_PASS, $options);

    bootstrapDatabase($pdo);

    return $pdo;
}

function bootstrapDatabase(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS customers (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            email VARCHAR(180) NOT NULL UNIQUE,
            phone VARCHAR(30) DEFAULT '',
            company VARCHAR(150) DEFAULT '',
            group_tag VARCHAR(80) DEFAULT 'General',
            status ENUM('active','inactive') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_customers_group (group_tag),
            INDEX idx_customers_status (status),
            INDEX idx_customers_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS email_logs (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_id INT UNSIGNED NULL,
            recipient_name VARCHAR(120) NOT NULL,
            recipient_email VARCHAR(180) NOT NULL,
            email_subject VARCHAR(255) NOT NULL,
            email_body MEDIUMTEXT NOT NULL,
            send_mode ENUM('all','group','selected') NOT NULL DEFAULT 'all',
            group_tag VARCHAR(80) DEFAULT NULL,
            is_html TINYINT(1) NOT NULL DEFAULT 1,
            status ENUM('sent','failed') NOT NULL,
            error_message TEXT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_email_logs_customer (customer_id),
            INDEX idx_email_logs_status (status),
            INDEX idx_email_logs_created (created_at),
            CONSTRAINT fk_email_logs_customer
                FOREIGN KEY (customer_id) REFERENCES customers(id)
                ON DELETE SET NULL ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function getDashboardSummary(PDO $pdo): array
{
    $customerSummary = $pdo->query(
        "SELECT
            COUNT(*) AS total_customers,
            COALESCE(SUM(status = 'active'), 0) AS active_customers
         FROM customers"
    )->fetch() ?: [];

    $mailSummary = $pdo->query(
        "SELECT
            COUNT(*) AS total_emails,
            COALESCE(SUM(status = 'sent'), 0) AS sent_emails,
            COALESCE(SUM(status = 'failed'), 0) AS failed_emails
         FROM email_logs"
    )->fetch() ?: [];

    return [
        'total_customers' => (int) ($customerSummary['total_customers'] ?? 0),
        'active_customers' => (int) ($customerSummary['active_customers'] ?? 0),
        'total_emails' => (int) ($mailSummary['total_emails'] ?? 0),
        'sent_emails' => (int) ($mailSummary['sent_emails'] ?? 0),
        'failed_emails' => (int) ($mailSummary['failed_emails'] ?? 0),
    ];
}

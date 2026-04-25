<?php
$host = '127.0.0.1';
$dbName = 'social_fitness';
$dbUser = 'root';
$dbPass = '';
$charset = 'utf8mb4';

$dsn = "mysql:host={$host};dbname={$dbName};charset={$charset}";
$serverDsn = "mysql:host={$host};charset={$charset}";
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
];

try {
    $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
} catch (PDOException $e) {
    if ((int) $e->getCode() === 1049) {
        try {
            // If the app database does not exist yet, create it automatically.
            $bootstrapPdo = new PDO($serverDsn, $dbUser, $dbPass, $options);
            $bootstrapPdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET {$charset} COLLATE {$charset}_unicode_ci");
            $pdo = new PDO($dsn, $dbUser, $dbPass, $options);
        } catch (PDOException $inner) {
            http_response_code(500);
            exit('Database bootstrap failed: ' . $inner->getMessage());
        }
    } else {
        http_response_code(500);
        exit('Database connection failed: ' . $e->getMessage());
    }
}

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS groups (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL UNIQUE,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB"
);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL UNIQUE,
        unique_id VARCHAR(80) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        group_id INT NULL,
        total_points DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_users_group FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE SET NULL,
        INDEX idx_users_group (group_id)
    ) ENGINE=InnoDB"
);

$pdo->exec("ALTER TABLE users MODIFY group_id INT NULL");

try {
    $pdo->exec("ALTER TABLE users DROP FOREIGN KEY fk_users_group");
} catch (PDOException $e) {
    // Ignore when constraint does not exist yet.
}

try {
    $pdo->exec("ALTER TABLE users ADD CONSTRAINT fk_users_group FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE SET NULL");
} catch (PDOException $e) {
    // Ignore when constraint already exists.
}

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS activities (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        activity_type VARCHAR(50) NOT NULL,
        duration_minutes INT NOT NULL,
        points_earned DECIMAL(10,2) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_activities_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_activities_user (user_id),
        INDEX idx_activities_created (created_at)
    ) ENGINE=InnoDB"
);

$pdo->exec(
    "CREATE TABLE IF NOT EXISTS notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        actor_user_id INT NOT NULL,
        type ENUM('overtook', 'closing_gap') NOT NULL,
        message VARCHAR(255) NOT NULL,
        is_read TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_notifications_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        CONSTRAINT fk_notifications_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE CASCADE,
        INDEX idx_notifications_user (user_id, created_at)
    ) ENGINE=InnoDB"
);

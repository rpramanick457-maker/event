<?php
require_once __DIR__ . '/config_loader.php';

$host = DB_HOST;
$port = DB_PORT;
$user = DB_USER;
$pass = DB_PASS;
$dbname = DB_NAME;

try {
    // Attempt 1: Try connecting directly to the database first (needed for cloud DBs where CREATE DATABASE is not allowed)
    $conn = new PDO("mysql:host=$host;port=$port;dbname=$dbname", $user, $pass, [
        PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true
    ]);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    // Attempt 2: If connecting directly failed (maybe DB doesn't exist), try to connect to the host and create database
    try {
        $conn = new PDO("mysql:host=$host;port=$port", $user, $pass, [
            PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => true
        ]);
        $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // Create DB if not exists
        $conn->exec("CREATE DATABASE IF NOT EXISTS `$dbname` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $conn->exec("USE `$dbname`");
    } catch (PDOException $e2) {
        throw new PDOException("Database connection failed: " . $e->getMessage() . " | " . $e2->getMessage(), (int)$e2->getCode());
    }
}
    
    // Create tables
    $conn->exec("CREATE TABLE IF NOT EXISTS `users` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `name` VARCHAR(100) NOT NULL,
      `email` VARCHAR(100) UNIQUE NOT NULL,
      `password` VARCHAR(255) NOT NULL,
      `otp_code` VARCHAR(255) NULL,
      `otp_expiry` DATETIME NULL,
      `is_verified` TINYINT DEFAULT 0,
      `role` VARCHAR(20) DEFAULT 'student',
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    
    $conn->exec("CREATE TABLE IF NOT EXISTS `events` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `title` VARCHAR(150) NOT NULL,
      `description` TEXT NOT NULL,
      `event_date` DATETIME NOT NULL,
      `location` VARCHAR(150) NOT NULL,
      `image_url` VARCHAR(255) NULL,
      `price` DECIMAL(10,2) DEFAULT 0.00,
      `is_active` TINYINT DEFAULT 1,
      `reg_status` VARCHAR(20) DEFAULT 'open',
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
    
    $conn->exec("CREATE TABLE IF NOT EXISTS `registrations` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `user_id` INT NOT NULL,
      `event_id` INT NOT NULL,
      `student_name` VARCHAR(100) NOT NULL,
      `roll_no` VARCHAR(50) NOT NULL,
      `batch` VARCHAR(50) NOT NULL,
      `stream` VARCHAR(50) NOT NULL,
      `food_preference` VARCHAR(50) NOT NULL,
      `event_role` VARCHAR(50) NOT NULL,
      `payment_screenshot` VARCHAR(255) NOT NULL,
      `status` VARCHAR(20) DEFAULT 'pending',
      `qr_token` VARCHAR(100) UNIQUE NULL,
      `qr_status` VARCHAR(20) DEFAULT 'inactive',
      `entry_time` DATETIME NULL,
      `exit_time` DATETIME NULL,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
      FOREIGN KEY (`event_id`) REFERENCES `events`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    $conn->exec("CREATE TABLE IF NOT EXISTS `helpers` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `name` VARCHAR(100) NOT NULL,
      `helper_key` VARCHAR(64) UNIQUE NOT NULL,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    $conn->exec("CREATE TABLE IF NOT EXISTS `system_logs` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `message` TEXT NOT NULL,
      `log_type` VARCHAR(50) NOT NULL,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");

    $conn->exec("CREATE TABLE IF NOT EXISTS `feedbacks` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `registration_id` INT UNIQUE NOT NULL,
      `student_name` VARCHAR(100) NOT NULL,
      `email` VARCHAR(100) NOT NULL,
      `event_id` INT NOT NULL,
      `rating` INT NOT NULL CHECK (`rating` BETWEEN 1 AND 5),
      `comment` TEXT NULL,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (`registration_id`) REFERENCES `registrations`(`id`) ON DELETE CASCADE,
      FOREIGN KEY (`event_id`) REFERENCES `events`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    $conn->exec("CREATE TABLE IF NOT EXISTS `certificates` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `user_id` INT NOT NULL,
      `event_id` INT NOT NULL,
      `pdf_path` VARCHAR(255) NOT NULL,
      `qr_path` VARCHAR(255) NOT NULL,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE,
      FOREIGN KEY (`event_id`) REFERENCES `events`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    $conn->exec("CREATE TABLE IF NOT EXISTS `event_reports` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `event_id` INT NOT NULL,
      `excel_path` VARCHAR(255) NOT NULL,
      `pdf_path` VARCHAR(255) NOT NULL,
      `registration_count` INT DEFAULT 0,
      `generated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (`event_id`) REFERENCES `events`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    $conn->exec("CREATE TABLE IF NOT EXISTS `activities` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `event_id` INT NOT NULL,
      `title` VARCHAR(100) NOT NULL,
      `activity_type` ENUM('solo', 'duet', 'group') NOT NULL,
      `description` TEXT NULL,
      `max_teams` INT DEFAULT 0,
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (`event_id`) REFERENCES `events`(`id`) ON DELETE CASCADE
    ) ENGINE=InnoDB");

    $conn->exec("CREATE TABLE IF NOT EXISTS `activity_registrations` (
      `id` INT AUTO_INCREMENT PRIMARY KEY,
      `activity_id` INT NOT NULL,
      `registration_id` INT NOT NULL,
      `team_name` VARCHAR(100) NULL,
      `team_leader_reg_id` INT NOT NULL,
      `track_link` VARCHAR(255) NULL,
      `status` VARCHAR(20) DEFAULT 'pending',
      `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      FOREIGN KEY (`activity_id`) REFERENCES `activities`(`id`) ON DELETE CASCADE,
      FOREIGN KEY (`registration_id`) REFERENCES `registrations`(`id`) ON DELETE CASCADE,
      FOREIGN KEY (`team_leader_reg_id`) REFERENCES `registrations`(`id`) ON DELETE CASCADE,
      UNIQUE KEY `unique_student_activity` (`activity_id`, `registration_id`)
    ) ENGINE=InnoDB");

    // Database Migration: Alter events table to add status columns if they do not exist
    try {
        $stmt_check = $conn->query("SHOW COLUMNS FROM `events` LIKE 'is_active'");
        $columns = $stmt_check->fetchAll();
        $stmt_check->closeCursor();
        if (empty($columns)) {
            $conn->exec("ALTER TABLE `events` ADD COLUMN `is_active` TINYINT DEFAULT 1");
        }
    } catch (PDOException $e) {
        // Table may not exist yet
    }
    try {
        $stmt_check = $conn->query("SHOW COLUMNS FROM `events` LIKE 'reg_status'");
        $columns = $stmt_check->fetchAll();
        $stmt_check->closeCursor();
        if (empty($columns)) {
            $conn->exec("ALTER TABLE `events` ADD COLUMN `reg_status` VARCHAR(20) DEFAULT 'open'");
        }
    } catch (PDOException $e) {
        // Table may not exist yet
    }

    try {
        $stmt_check = $conn->query("SHOW COLUMNS FROM `events` LIKE 'certificate_template'");
        $columns = $stmt_check->fetchAll();
        $stmt_check->closeCursor();
        if (empty($columns)) {
            $conn->exec("ALTER TABLE `events` ADD COLUMN `certificate_template` VARCHAR(255) NULL");
        }
    } catch (PDOException $e) {
    }

    try {
        $stmt_check = $conn->query("SHOW COLUMNS FROM `events` LIKE 'certificate_published'");
        $columns = $stmt_check->fetchAll();
        $stmt_check->closeCursor();
        if (empty($columns)) {
            $conn->exec("ALTER TABLE `events` ADD COLUMN `certificate_published` TINYINT DEFAULT 0");
        }
    } catch (PDOException $e) {
    }

    try {
        $conn->exec("ALTER TABLE `users` MODIFY COLUMN `otp_code` VARCHAR(255) NULL");
    } catch (PDOException $e) {
        // Table or column may not exist yet
    }

    // Database Migration: Alter registrations table to add stream column if it does not exist
    try {
        $stmt_check = $conn->query("SHOW COLUMNS FROM `registrations` LIKE 'stream'");
        $columns = $stmt_check->fetchAll();
        $stmt_check->closeCursor();
        if (empty($columns)) {
            $conn->exec("ALTER TABLE `registrations` ADD COLUMN `stream` VARCHAR(50) NOT NULL AFTER `batch`");
        }
    } catch (PDOException $e) {
        // Table or column may not exist yet
    }

    // Database Migration: Alter registrations table to add report_id column if it does not exist
    try {
        $stmt_check = $conn->query("SHOW COLUMNS FROM `registrations` LIKE 'report_id'");
        $columns = $stmt_check->fetchAll();
        $stmt_check->closeCursor();
        if (empty($columns)) {
            $conn->exec("ALTER TABLE `registrations` ADD COLUMN `report_id` INT DEFAULT NULL AFTER `event_id`");
            $conn->exec("ALTER TABLE `registrations` ADD CONSTRAINT `fk_registrations_report` FOREIGN KEY (`report_id`) REFERENCES `event_reports`(`id`) ON DELETE SET NULL");
        }
    } catch (PDOException $e) {
        // Table or column may not exist yet
    }

    // Seed default admin if not exists
    $stmt = $conn->prepare("SELECT COUNT(*) FROM `users` WHERE `role` = 'admin'");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $admin_email = 'admin@event.com';
        $admin_pass = password_hash('admin123', PASSWORD_DEFAULT);
        $admin_name = 'System Administrator';
        
        $insert = $conn->prepare("INSERT INTO `users` (`name`, `email`, `password`, `is_verified`, `role`) VALUES (?, ?, ?, 1, 'admin')");
        $insert->execute([$admin_name, $admin_email, $admin_pass]);
    }
    
    // Seed default events if empty
    $stmt = $conn->prepare("SELECT COUNT(*) FROM `events`");
    $stmt->execute();
    if ($stmt->fetchColumn() == 0) {
        $events = [
            [
                'title' => 'Annual Hackathon 2026',
                'description' => 'Code your way to glory! A 36-hour non-stop hackathon with amazing prizes, tech mentors, and free catering. Form your teams and show off your building skills.',
                'event_date' => '2026-07-15 09:00:00',
                'location' => 'Main Seminar Hall',
                'price' => 150.00,
                'image_url' => 'images/hackathon.jpg'
            ],
            [
                'title' => 'Cybersecurity Workshop',
                'description' => 'Learn the fundamentals of web security, penetration testing, and digital forensics from industry experts. Hands-on labs included.',
                'event_date' => '2026-08-05 10:00:00',
                'location' => 'Lab 4, IT Department',
                'price' => 100.00,
                'image_url' => 'images/cybersecurity.jpg'
            ],
            [
                'title' => 'Campus Tech Symposium',
                'description' => 'A gathering of tech enthusiasts to present research papers, discuss emergent AI technologies, and network with leading recruiters.',
                'event_date' => '2026-09-20 09:30:00',
                'location' => 'Auditorium A',
                'price' => 50.00,
                'image_url' => 'images/symposium.jpg'
            ]
        ];
        
        $insert = $conn->prepare("INSERT INTO `events` (`title`, `description`, `event_date`, `location`, `price`, `image_url`) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($events as $e) {
            $insert->execute([$e['title'], $e['description'], $e['event_date'], $e['location'], $e['price'], $e['image_url']]);
        }
    }

} catch (PDOException $e) {
    die("Database Connection Error: " . $e->getMessage());
}

function logSystemMessage($conn, $message, $log_type = 'info') {
    try {
        $stmt = $conn->prepare("INSERT INTO system_logs (message, log_type) VALUES (?, ?)");
        $stmt->execute([$message, $log_type]);
    } catch (PDOException $e) {
        // Fail silently or handle connection/log failure gracefully
    }
}

/**
 * Detect active ngrok tunnel URL or fall back to the LAN IP address.
 */
function getActiveTunnelOrLanUrl() {
    // 1. Try fetching from ngrok local API
    $ctx = stream_context_create([
        'http' => ['timeout' => 0.15] // 150ms timeout to avoid blocking page loads
    ]);
    $api_json = @file_get_contents('http://127.0.0.1:4040/api/tunnels', false, $ctx);
    if ($api_json) {
        $data = json_decode($api_json, true);
        if (!empty($data['tunnels'])) {
            foreach ($data['tunnels'] as $t) {
                if ($t['proto'] === 'https') {
                    return $t['public_url'];
                }
            }
            return $data['tunnels'][0]['public_url'];
        }
    }
    
    // 2. If no ngrok tunnel, fall back to LAN IP address
    $lan_ip = gethostbyname(gethostname());
    return "http://" . $lan_ip;
}

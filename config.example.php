<?php
// config.example.php
// Copy this file to config.php and update with your actual credentials.

// Database configuration
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '3306');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'event_db');

// SMTP configuration
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_AUTH', true);
define('SMTP_USER', 'your_email@gmail.com');
define('SMTP_PASS', 'your_app_password');
define('SMTP_SECURE', 'tls');
define('SMTP_PORT', 587);
define('SMTP_FROM_EMAIL', 'your_email@gmail.com');
define('SMTP_FROM_NAME', 'Nexus Campus Portal');

// Security key (Generate a 32-character random string for AES-256-CBC)
define('AES_SECRET_KEY', 'some_random_32_char_secret_key');

<?php
// config_loader.php
// Dynamically loads configuration from config.php if available, or from environment variables (e.g., on Vercel)

// Disable deprecation warnings (e.g. PHP 8.5 deprecation warnings on Vercel)
error_reporting(E_ALL & ~E_DEPRECATED);

if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
} else {
    // Database configuration
    if (!defined('DB_HOST')) define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
    if (!defined('DB_PORT')) define('DB_PORT', getenv('DB_PORT') ?: '3306');
    if (!defined('DB_USER')) define('DB_USER', getenv('DB_USER') ?: 'root');
    if (!defined('DB_PASS')) define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : '');
    if (!defined('DB_NAME')) define('DB_NAME', getenv('DB_NAME') ?: 'event_db');

    // SMTP configuration
    if (!defined('SMTP_HOST')) define('SMTP_HOST', getenv('SMTP_HOST') ?: 'smtp.gmail.com');
    if (!defined('SMTP_AUTH')) define('SMTP_AUTH', getenv('SMTP_AUTH') !== false ? filter_var(getenv('SMTP_AUTH'), FILTER_VALIDATE_BOOLEAN) : true);
    if (!defined('SMTP_USER')) define('SMTP_USER', getenv('SMTP_USER') ?: 'rpramanick457@gmail.com');
    if (!defined('SMTP_PASS')) define('SMTP_PASS', getenv('SMTP_PASS') ?: 'obyc xyro qhwz zvqm');
    if (!defined('SMTP_SECURE')) define('SMTP_SECURE', getenv('SMTP_SECURE') ?: 'tls');
    if (!defined('SMTP_PORT')) define('SMTP_PORT', getenv('SMTP_PORT') ?: 587);
    if (!defined('SMTP_FROM_EMAIL')) define('SMTP_FROM_EMAIL', getenv('SMTP_FROM_EMAIL') ?: 'rpramanick457@gmail.com');
    if (!defined('SMTP_FROM_NAME')) define('SMTP_FROM_NAME', getenv('SMTP_FROM_NAME') ?: 'Nexus Campus Portal');

    // Security key (AES-256-CBC 32-character key)
    if (!defined('AES_SECRET_KEY')) define('AES_SECRET_KEY', getenv('AES_SECRET_KEY') ?: 'Nexus@2026#SecureKey$RahulAdmin!');
}

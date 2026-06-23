<?php
// Async mail dispatcher
if ($argc < 4) {
    exit("Usage: php send_email_async.php <email> <otp_or_reg_id> <type>\n");
}

$email = $argv[1];
$otp_or_id = $argv[2];
$type = $argv[3];

// Include db_connect first so that $conn is initialized for SQL queries
require_once __DIR__ . '/db_connect.php';
// Include otp_handler to use mail functions
require_once __DIR__ . '/otp_handler.php';

if ($type === 'checkout') {
    // Send checkout confirmation email
    sendCheckoutEmail(intval($otp_or_id));
} else {
    // Send regular OTP verification email
    sendMockOTP($email, $otp_or_id, $type);
}

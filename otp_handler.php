<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Clean up any remaining simulated mailbox session data
if (isset($_SESSION['simulated_emails']) || isset($_SESSION['otp_toast']) || isset($_SESSION['otp_toast_email'])) {
    unset($_SESSION['simulated_emails']);
    unset($_SESSION['otp_toast']);
    unset($_SESSION['otp_toast_email']);
}

/**
 * Generate a 6-digit numeric OTP.
 */
function generateOTP() {
    return strval(rand(100000, 999999));
}

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/security.php';

/**
 * Sends a real OTP verification email using Google's SMTP servers.
 */
function sendMockOTP($email, $otp, $type = 'Registration') {
    // Sanitize email by trimming whitespace and quotes
    $email = trim($email);
    $email = trim($email, "\"'");
    
    // Reroute admin/test placeholder emails to the user's real email
    if (strcasecmp($email, 'admin@event.com') === 0 || stripos($email, '@event.com') !== false) {
        $email = 'rpramanick457@gmail.com';
    }
    
    $mail = new PHPMailer(true);
    try {
        // SMTP Settings
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = SMTP_AUTH;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port       = SMTP_PORT;

        // Recipients
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($email);

        // Content
        $mail->isHTML(true);
        $mail->Subject = "$type Verification Code - Nexus";
        
        $mail->Body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2d4c6; border-radius: 8px; background-color: #faf5ed; color: #2e2218;'>
            <h2 style='color: #ff6b00; text-align: center; border-bottom: 2px solid #ff6b00; padding-bottom: 10px;'>Nexus Verification</h2>
            <p>Hello,</p>
            <p>You requested a verification code to complete your <strong>$type</strong> on the Nexus Campus Portal.</p>
            <div style='text-align: center; margin: 30px 0;'>
                <span style='font-size: 32px; font-weight: bold; letter-spacing: 5px; color: #ff6b00; background-color: #fff; padding: 15px 30px; border: 1px dashed #ff6b00; border-radius: 4px; display: inline-block;'>$otp</span>
            </div>
            <p style='color: #635143; font-size: 14px;'>This code is valid for 5 minutes. Please do not share this code with anyone.</p>
            <hr style='border: none; border-top: 1px solid #e2d4c6; margin-top: 30px;'>
            <p style='font-size: 12px; color: #948475; text-align: center;'>This is an automated message, please do not reply to this email.</p>
        </div>
        ";
        
        $mail->AltBody = "Your Nexus $type Verification Code is: $otp";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("PHPMailer Error: " . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Render the OTP notification toast (disabled, sending real emails directly).
 */
function renderOTPToast() {
    // No-op: No UI popups or toasts are needed anymore.
}

/**
 * Triggers sending an OTP email asynchronously in the background.
 */
function triggerAsyncOTP($email, $otp, $type = 'Registration') {
    // If running on Vercel or if popen function is disabled, run synchronously
    if (getenv('VERCEL') || !function_exists('popen')) {
        return sendMockOTP($email, $otp, $type);
    }

    // Escape arguments for the command line
    $cmd = "php " . escapeshellarg(__DIR__ . "/send_email_async.php") . " " . escapeshellarg($email) . " " . escapeshellarg($otp) . " " . escapeshellarg($type);
    
    // On Windows, start /B runs the process in the background
    if (stristr(PHP_OS, 'WIN')) {
        pclose(popen("start /B " . $cmd, "r"));
    } else {
        // Fallback for non-Windows systems (Linux/macOS)
        pclose(popen($cmd . " > /dev/null 2>&1 &", "r"));
    }
}

/**
 * Sends a checkout confirmation email to the student with a Feedback QR Code.
 */
function sendCheckoutEmail($reg_id) {
    global $conn;
    
    // Fetch registration and event details
    try {
        $stmt = $conn->prepare("
            SELECT r.*, e.title as event_title, u.email as registered_email
            FROM registrations r 
            JOIN events e ON r.event_id = e.id 
            JOIN users u ON r.user_id = u.id
            WHERE r.id = ?
        ");
        $stmt->execute([$reg_id]);
        $reg = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$reg) {
            return false;
        }
        
        $email = $reg['registered_email'];
        // Reroute admin placeholder email to user's real email for testing
        $email = trim($email);
        $email = trim($email, "\"'");
        if (strcasecmp($email, 'admin@event.com') === 0 || stripos($email, '@event.com') !== false || strcasecmp($email, 'rpramanick457@gmail.com') === 0) {
            $email = 'rpramanick457@gmail.com';
        }
        
        $student_name = decryptData($reg['student_name']);
        $event_title = $reg['event_title'];
        $qr_token = $reg['qr_token'];
        
        // Generate feedback link using dynamic host (preferring LAN IP over localhost so mobile users can open it)
        $http_host = $_SERVER['HTTP_HOST'] ?? '';
        if (empty($http_host) || strpos($http_host, 'localhost') !== false || strpos($http_host, '127.0.0.1') !== false) {
            $server_ip = gethostbyname(gethostname());
            if (!empty($http_host) && strpos($http_host, ':') !== false) {
                $port = explode(':', $http_host)[1];
                $host_val = $server_ip . ':' . $port;
            } else {
                $host_val = $server_ip;
            }
        } else {
            $host_val = $http_host;
        }
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
        $feedback_link = "{$protocol}://{$host_val}/event/feedback.php?token=" . urlencode($qr_token);
        
        // Dynamic QR code pointing to feedback link using api.qrserver.com
        $qr_api_url = "https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=" . urlencode($feedback_link);
        
        $mail = new PHPMailer(true);
        
        // SMTP Settings
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = SMTP_AUTH;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = SMTP_SECURE;
        $mail->Port       = SMTP_PORT;
        
        // Recipients
        $mail->setFrom(SMTP_FROM_EMAIL, SMTP_FROM_NAME);
        $mail->addAddress($email);
        
        // Content
        $mail->isHTML(true);
        $mail->Subject = "Event Check-Out Confirmation";
        
        $mail->Body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e2d4c6; border-radius: 8px; background-color: #faf5ed; color: #2e2218;'>
            <h2 style='color: #ff6b00; text-align: center; border-bottom: 2px solid #ff6b00; padding-bottom: 10px;'>Event Check-Out Confirmation</h2>
            <p>Dear {$student_name},</p>
            <p>Your check-out from the event <strong>{$event_title}</strong> has been completed successfully.</p>
            <p>Thank you for participating in the event.</p>
            <p>We would appreciate your feedback to help us improve future events. Please scan the QR code below or click the button to submit your feedback:</p>
            
            <div style='text-align: center; margin: 30px 0;'>
                <img src='{$qr_api_url}' alt='Feedback QR Code' style='border: 1px solid #ff6b00; padding: 10px; border-radius: 8px; background: white;'><br><br>
                <a href='{$feedback_link}' style='display: inline-block; background-color: #ff6b00; color: white; padding: 12px 24px; font-weight: bold; text-decoration: none; border-radius: 4px; box-shadow: 0 4px 10px rgba(255, 107, 0, 0.2);'>Give Feedback</a>
            </div>
            
            <hr style='border: none; border-top: 1px solid #e2d4c6; margin-top: 30px;'>
            <p style='font-size: 12px; color: #948475; text-align: center;'>This is an automated message, please do not reply directly to this email.</p>
        </div>
        ";
        
        $mail->AltBody = "Dear {$student_name},\n\nYour check-out from event '{$event_title}' was successful. Please submit your feedback at: {$feedback_link}\n\nThank you!";
        
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("PHPMailer Checkout Error: " . $mail->ErrorInfo);
        return false;
    }
}

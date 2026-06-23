<?php
header('Content-Type: application/json');
require_once 'db_connect.php';
require_once 'otp_handler.php';
require_once 'security.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check parameters
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request method. Only POST is allowed.'
    ]);
    exit();
}

$input = json_decode(file_get_contents('php://input'), true);
$token = isset($input['token']) ? trim($input['token']) : '';
$helper_key = isset($input['helper_key']) ? trim($input['helper_key']) : '';

// Verify permission (either Admin Session or Valid Helper Key)
$is_authorized = false;
$authorized_by = '';
if (isset($_SESSION['user_id']) && $_SESSION['user_role'] === 'admin') {
    $is_authorized = true;
    $authorized_by = 'Administrator';
} elseif (!empty($helper_key)) {
    try {
        $helper_stmt = $conn->prepare("SELECT name FROM helpers WHERE helper_key = ?");
        $helper_stmt->execute([$helper_key]);
        $helper = $helper_stmt->fetch(PDO::FETCH_ASSOC);
        if ($helper) {
            $is_authorized = true;
            $authorized_by = "helper '" . $helper['name'] . "'";
        }
    } catch (PDOException $e) {
        // Database connection or table error
    }
}
// If no admin session and no helper key, allow a public scan (e.g., student self‑scan) but flag it
if (!$is_authorized && empty($helper_key)) {
    $is_authorized = true;
    $authorized_by = 'Public Scan (no auth)';
}
if (!$is_authorized) {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access. Valid administrator session or helper passcode required.'
    ]);
    exit();
}

if (empty($token)) {
    echo json_encode([
        'success' => false,
        'message' => 'QR Code token is missing.'
    ]);
    exit();
}

try {
    // Find registration matching token (and get student email)
    $stmt = $conn->prepare("
        SELECT r.*, e.title as event_title, u.email as registered_email 
        FROM registrations r 
        JOIN events e ON r.event_id = e.id 
        JOIN users u ON r.user_id = u.id
        WHERE r.qr_token = ?
    ");
    $stmt->execute([$token]);
    $reg = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$reg) {
        logSystemMessage($conn, "Scan failed: Invalid QR code scanned by $authorized_by. Token: " . substr($token, 0, 8) . "...", "error");
        echo json_encode([
            'success' => false,
            'message' => 'Invalid QR Code. No registration found matching this ticket.'
        ]);
        exit();
    }

    // Decrypt sensitive fields
    $reg['student_name']    = decryptData($reg['student_name']);
    $reg['roll_no']         = decryptData($reg['roll_no']);
    $reg['batch']           = decryptData($reg['batch']);
    $reg['food_preference'] = decryptData($reg['food_preference']);
    
    // Check registration approval status
    if ($reg['status'] !== 'approved') {
        logSystemMessage($conn, "Scan failed: Access Denied for student {$reg['student_name']} (Roll: {$reg['roll_no']}) scanning event '{$reg['event_title']}' by $authorized_by. Registration is {$reg['status']}.", "error");
        echo json_encode([
            'success' => false,
            'message' => 'Access Denied. Registration status is "' . ucfirst($reg['status']) . '". Only approved registrations are active.'
        ]);
        exit();
    }
    
    $qr_status = $reg['qr_status'];
    $current_time = date('Y-m-d H:i:s');
    $formatted_time = date('M d, Y H:i:s');
    
    if ($qr_status === 'inactive') {
        // MARK ENTRY
        $update = $conn->prepare("UPDATE registrations SET qr_status = 'active', entry_time = ? WHERE id = ?");
        $update->execute([$current_time, $reg['id']]);
        
        logSystemMessage($conn, "Check-in successful: Student {$reg['student_name']} (Roll: {$reg['roll_no']}) checked into event '{$reg['event_title']}' as {$reg['event_role']} by $authorized_by.", "success");
        
        echo json_encode([
            'success' => true,
            'type' => 'entry',
            'student_name' => $reg['student_name'],
            'roll_no' => $reg['roll_no'],
            'batch' => $reg['batch'],
            'event_title' => $reg['event_title'],
            'event_role' => $reg['event_role'],
            'food_preference' => $reg['food_preference'],
            'payment_screenshot' => $reg['payment_screenshot'],
            'timestamp' => $formatted_time,
            'message' => 'ENTRY MARKED SUCCESSFUL! Participant checked in.'
        ]);
        exit();
        
    } elseif ($qr_status === 'active') {
        // MARK EXIT
        $update = $conn->prepare("UPDATE registrations SET qr_status = 'deactivated', exit_time = ? WHERE id = ?");
        $update->execute([$current_time, $reg['id']]);
        
        logSystemMessage($conn, "Check-out successful: Student {$reg['student_name']} (Roll: {$reg['roll_no']}) checked out of event '{$reg['event_title']}' by $authorized_by. Ticket deactivated.", "success");
        
        // Trigger async background email send for checkout feedback
        triggerAsyncOTP($reg['registered_email'], strval($reg['id']), 'checkout');
        
        echo json_encode([
            'success' => true,
            'type' => 'exit',
            'student_name' => $reg['student_name'],
            'roll_no' => $reg['roll_no'],
            'batch' => $reg['batch'],
            'event_title' => $reg['event_title'],
            'event_role' => $reg['event_role'],
            'food_preference' => $reg['food_preference'],
            'payment_screenshot' => $reg['payment_screenshot'],
            'timestamp' => $formatted_time,
            'message' => 'EXIT MARKED SUCCESSFUL! Ticket deactivated.'
        ]);
        exit();
        
    } else {
        // DEACTIVATED
        logSystemMessage($conn, "Scan failed: Already deactivated ticket scanned for student {$reg['student_name']} (Roll: {$reg['roll_no']}) for event '{$reg['event_title']}' by $authorized_by.", "error");
        echo json_encode([
            'success' => false,
            'message' => 'Ticket is DEACTIVATED. Exit was already processed at ' . date('M d, Y H:i:s', strtotime($reg['exit_time']))
        ]);
        exit();
    }
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
    exit();
}

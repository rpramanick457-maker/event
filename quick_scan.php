<?php
// quick_scan.php – Native camera QR scan receiver
require_once 'db_connect.php';
require_once 'otp_handler.php';
require_once 'security.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$token = isset($_GET['token']) ? trim($_GET['token']) : '';
$error = '';
$success = '';
$reg_data = null;
$scan_type = '';
$helper_name = '';

// --- AUTHENTICATION CHECK ---
$is_authenticated = false;

// Check if admin is logged in
if (isset($_SESSION['user_id']) && $_SESSION['user_role'] === 'admin') {
    $is_authenticated = true;
    $helper_name = 'Administrator';
}

// Check if authenticated helper key is stored in cookies/session
$helper_key = $_SESSION['quick_scan_helper_key'] ?? $_COOKIE['quick_scan_helper_key'] ?? '';
if (!$is_authenticated && !empty($helper_key)) {
    try {
        $stmt = $conn->prepare("SELECT name FROM helpers WHERE helper_key = ?");
        $stmt->execute([$helper_key]);
        $helper = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($helper) {
            $is_authenticated = true;
            $helper_name = $helper['name'];
        }
    } catch (PDOException $e) {
        $error = 'Database error: ' . $e->getMessage();
    }
}

// Handle Passcode verification form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_auth'])) {
    $entered_passcode = trim($_POST['passcode'] ?? '');
    try {
        $stmt = $conn->prepare("SELECT name FROM helpers WHERE helper_key = ?");
        $stmt->execute([$entered_passcode]);
        $helper = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($helper) {
            // Store authentication in session and 30-day cookie
            $_SESSION['quick_scan_helper_key'] = $entered_passcode;
            setcookie('quick_scan_helper_key', $entered_passcode, time() + (86400 * 30), "/");
            $is_authenticated = true;
            $helper_name = $helper['name'];
        } else {
            $error = 'Invalid helper passcode. Please check with your administrator.';
        }
    } catch (PDOException $e) {
        $error = 'Database error: ' . $e->getMessage();
    }
}

// --- SCAN PROCESSING ---
if ($is_authenticated && !empty($token)) {
    try {
        // Fetch registration and event info
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
            $error = 'Invalid ticket QR code. No registration found.';
            logSystemMessage($conn, "Native scan failed: Invalid QR code scanned by $helper_name.", "error");
        } else {
            // Decrypt fields
            $reg['student_name']    = decryptData($reg['student_name']);
            $reg['roll_no']         = decryptData($reg['roll_no']);
            $reg['batch']           = decryptData($reg['batch']);
            $reg['food_preference'] = decryptData($reg['food_preference']);

            if ($reg['status'] !== 'approved') {
                $error = 'Access Denied. Registration status is "' . ucfirst($reg['status']) . '". Only approved tickets are valid.';
                logSystemMessage($conn, "Native scan failed: Registration status was {$reg['status']} for {$reg['student_name']}.", "error");
            } else {
                $qr_status = $reg['qr_status'];
                $current_time = date('Y-m-d H:i:s');
                $reg_data = $reg;

                if ($qr_status === 'inactive') {
                    // Perform ENTRY
                    $update = $conn->prepare("UPDATE registrations SET qr_status = 'active', entry_time = ? WHERE id = ?");
                    $update->execute([$current_time, $reg['id']]);
                    $scan_type = 'entry';
                    $success = 'CHECK-IN SUCCESSFUL';
                    logSystemMessage($conn, "Native check-in successful: Student {$reg['student_name']} checked into event '{$reg['event_title']}' by $helper_name.", "success");
                } elseif ($qr_status === 'active') {
                    // Perform EXIT
                    $update = $conn->prepare("UPDATE registrations SET qr_status = 'deactivated', exit_time = ? WHERE id = ?");
                    $update->execute([$current_time, $reg['id']]);
                    $scan_type = 'exit';
                    $success = 'CHECK-OUT SUCCESSFUL';
                    logSystemMessage($conn, "Native check-out successful: Student {$reg['student_name']} checked out of event '{$reg['event_title']}' by $helper_name.", "success");
                    
                    // Trigger feedback email
                    triggerAsyncOTP($reg['registered_email'], strval($reg['id']), 'checkout');
                } else {
                    // Already deactivated
                    $error = 'Ticket is already DEACTIVATED. Exit was processed at ' . date('M d, Y H:i', strtotime($reg['exit_time']));
                    logSystemMessage($conn, "Native scan failed: Ticket already deactivated for {$reg['student_name']}.", "error");
                }
            }
        }
    } catch (PDOException $e) {
        $error = 'Database error: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quick Scan Check-In | Nexus</title>
    <link rel="stylesheet" href="css/style.css?v=2.0">
    <style>
        body {
            background: #0A0706;
            color: #FCF8F4;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem 1rem;
            font-family: 'Plus Jakarta Sans', 'Outfit', sans-serif;
        }

        .scan-card {
            width: 100%;
            max-width: 440px;
            background: rgba(32, 23, 17, 0.75);
            backdrop-filter: blur(32px);
            -webkit-backdrop-filter: blur(32px);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 24px;
            padding: 2.25rem 1.75rem;
            text-align: center;
            box-shadow: 0 20px 50px rgba(0,0,0,0.3);
        }

        .scan-header {
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            font-size: 1.4rem;
            color: #FCF8F4;
            margin-bottom: 1.5rem;
            letter-spacing: -0.02em;
        }
        .scan-header span { color: #FFA852; }

        .status-badge {
            font-size: 2.5rem;
            margin-bottom: 1rem;
            animation: bounce 0.6s ease;
        }

        .status-title {
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            font-size: 1.3rem;
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .status-desc {
            color: #CBB6A5;
            font-size: 0.9rem;
            line-height: 1.5;
            margin-bottom: 1.5rem;
        }

        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
            margin-bottom: 1.5rem;
            text-align: left;
        }

        .info-cell {
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 12px;
            padding: 0.75rem;
        }
        .info-cell.full-width { grid-column: 1 / -1; }

        .info-label {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            color: #8A7566;
            margin-bottom: 0.2rem;
            letter-spacing: 0.05em;
        }

        .info-val {
            font-size: 0.95rem;
            font-weight: 700;
            color: #FCF8F4;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .btn-action {
            background: linear-gradient(135deg, #FFA852, #FF6B35);
            border: none;
            border-radius: 12px;
            padding: 0.85rem 1.5rem;
            color: #130E0A;
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            font-size: 0.95rem;
            cursor: pointer;
            width: 100%;
            transition: all 0.2s;
            box-shadow: 0 4px 20px rgba(255,168,82,0.3);
        }

        .btn-action:active { transform: scale(0.97); }

        .footer-text {
            font-size: 0.75rem;
            color: #8A7566;
            margin-top: 1.5rem;
            border-top: 1px solid rgba(255,255,255,0.06);
            padding-top: 1rem;
        }

        /* Forms styling */
        .form-group {
            text-align: left;
            margin-bottom: 1.25rem;
        }
        .form-label {
            font-size: 0.8rem;
            font-weight: 700;
            color: #CBB6A5;
            margin-bottom: 0.5rem;
            display: block;
        }
        .form-input {
            width: 100%;
            background: rgba(44, 32, 24, 0.9);
            border: 1.5px solid rgba(255,255,255,0.1);
            border-radius: 10px;
            padding: 0.75rem 1rem;
            color: #FCF8F4;
            font-size: 0.95rem;
            box-sizing: border-box;
        }
        .form-input:focus {
            outline: none;
            border-color: #FFA852;
        }

        @keyframes bounce {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.15); }
        }
    </style>
</head>
<body>

<div class="scan-card">
    <div class="logo-container" style="justify-content: center; margin-bottom: 1.5rem;">
        <img src="images/nexus_logo.png" alt="Nexus Logo" class="logo-img" style="height: 32px;">
        <span class="logo-text" style="font-size: 1.4rem; color: #FCF8F4;">Nexus</span>
    </div>

    <?php if (!$is_authenticated): ?>
        <!-- PASSCODE DEMAND STATE -->
        <div class="status-badge">🔒</div>
        <div class="status-title" style="color: #FFA852;">Verification Required</div>
        <p class="status-desc">Enter your volunteer passcode below to verify your scan credentials and process this check-in.</p>
        
        <?php if (!empty($error)): ?>
            <div style="background:rgba(248,113,113,0.1); border:1px solid rgba(248,113,113,0.25); color:#FCA5A5; border-radius:10px; padding:0.75rem 1rem; font-size:0.85rem; margin-bottom:1.25rem; text-align:left;">
                ⚠️ <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form action="" method="POST">
            <div class="form-group">
                <label class="form-label" for="passcode-input">Helper Passcode</label>
                <input type="password" id="passcode-input" name="passcode" class="form-input" placeholder="Enter passcode..." required autocomplete="off">
            </div>
            <button type="submit" name="action_auth" class="btn-action">Verify & Authenticate</button>
        </form>

    <?php elseif (empty($token)): ?>
        <!-- NO TOKEN SCAN INSTRUCTIONS STATE -->
        <div class="status-badge">📸</div>
        <div class="status-title" style="color: #FFA852;">Ready to Scan</div>
        <p class="status-desc">Logged in as <strong><?php echo htmlspecialchars($helper_name); ?></strong>.</p>
        <p class="status-desc" style="font-size: 0.85rem; color: #8A7566;">To scan student tickets, open your phone's built-in Camera App, point it at the student's ticket QR code, and tap the link that appears.</p>
        
        <div style="margin-top: 1.5rem; text-align: left; padding: 1rem; background: rgba(52,211,153,0.05); border: 1px solid rgba(52,211,153,0.15); border-radius: 12px; font-size: 0.8rem; color: #A39081;">
            💡 <strong>Why use this method?</strong> You scan using your native camera. No browser settings tweaks or SSL warning procedures are required!
        </div>

    <?php elseif (!empty($error)): ?>
        <!-- ERROR PROCESSING STATE -->
        <div class="status-badge">❌</div>
        <div class="status-title" style="color: #F87171;">Scan Failed</div>
        <p class="status-desc" style="color: #FCA5A5; font-weight: bold;"><?php echo htmlspecialchars($error); ?></p>
        
        <button onclick="window.close();" class="btn-action" style="background: rgba(255,255,255,0.08); border:1px solid rgba(255,255,255,0.15); color: #FCF8F4; box-shadow: none;">Dismiss Scan</button>

        <div class="footer-text">Verified by: <strong><?php echo htmlspecialchars($helper_name); ?></strong></div>

    <?php else: ?>
        <!-- SUCCESS STATE -->
        <div class="status-badge"><?php echo ($scan_type === 'entry') ? '✅' : '🔵'; ?></div>
        <div class="status-title" style="color: <?php echo ($scan_type === 'entry') ? '#34D399' : '#63B3ED'; ?>;"><?php echo htmlspecialchars($success); ?></div>
        <p class="status-desc"><?php echo ($scan_type === 'entry') ? 'Participant entry has been registered.' : 'Participant exit has been registered. Ticket deactivated.'; ?></p>

        <div class="info-grid">
            <div class="info-cell full-width">
                <div class="info-label">🎫 Event</div>
                <div class="info-val" style="color:#FFA852;"><?php echo htmlspecialchars($reg_data['event_title']); ?></div>
            </div>
            <div class="info-cell">
                <div class="info-label">👤 Student</div>
                <div class="info-val"><?php echo htmlspecialchars($reg_data['student_name']); ?></div>
            </div>
            <div class="info-cell">
                <div class="info-label">🎓 Roll / Batch</div>
                <div class="info-val"><?php echo htmlspecialchars($reg_data['roll_no'] . ' / ' . $reg_data['batch']); ?></div>
            </div>
            <div class="info-cell">
                <div class="info-label">🍽️ Food</div>
                <div class="info-val"><?php echo htmlspecialchars($reg_data['food_preference']); ?></div>
            </div>
            <div class="info-cell">
                <div class="info-label">🏷️ Role</div>
                <div class="info-val"><?php echo htmlspecialchars($reg_data['event_role']); ?></div>
            </div>
        </div>

        <button onclick="window.close();" class="btn-action">Scan Another Ticket</button>

        <div class="footer-text">Verified by: <strong><?php echo htmlspecialchars($helper_name); ?></strong></div>
    <?php endif; ?>
</div>

</body>
</html>

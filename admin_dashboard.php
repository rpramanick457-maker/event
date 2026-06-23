<?php
ob_start();
require_once 'db_connect.php';
require_once 'otp_handler.php';
require_once 'certificate_helper.php';
require_once 'security.php';

$detected_base_url = getActiveTunnelOrLanUrl();

// Verify admin login status
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

// Fetch the latest admin details from the database to keep session in sync
try {
    $stmt_user = $conn->prepare("SELECT name, email FROM users WHERE id = ?");
    $stmt_user->execute([$_SESSION['user_id']]);
    $db_user = $stmt_user->fetch(PDO::FETCH_ASSOC);
    if ($db_user) {
        $_SESSION['user_name'] = $db_user['name'];
        $_SESSION['user_email'] = $db_user['email'];
    }
} catch (Exception $e) {
    // Fail silently, use existing session value
}

$user_email = $_SESSION['user_email'] ?? 'admin@event.com';
$gravatar_url = "https://www.gravatar.com/avatar/" . md5(strtolower(trim($user_email))) . "?d=mp&s=80";

$error = '';
$success = '';

if (isset($_SESSION['success_message'])) {
    $success = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}
if (isset($_SESSION['error_message'])) {
    $error = $_SESSION['error_message'];
    unset($_SESSION['error_message']);
}

// Handle Administrative Actions with System Audit Logging
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action_approve'])) {
        $reg_id = intval($_POST['reg_id']);
        
        try {
            // Fetch student name and event title for detailed logging
            $stmt_reg = $conn->prepare("SELECT r.student_name, r.roll_no, e.title FROM registrations r JOIN events e ON r.event_id = e.id WHERE r.id = ?");
            $stmt_reg->execute([$reg_id]);
            $reg_info = $stmt_reg->fetch(PDO::FETCH_ASSOC);
            $student_name = $reg_info ? decryptData($reg_info['student_name']) : "Unknown";
            $roll_no = $reg_info ? decryptData($reg_info['roll_no']) : "Unknown";
            $event_title = $reg_info ? $reg_info['title'] : "Unknown Event";

            $qr_token = bin2hex(random_bytes(16));
            $stmt = $conn->prepare("UPDATE registrations SET status = 'approved', qr_token = ?, qr_status = 'inactive' WHERE id = ?");
            $stmt->execute([$qr_token, $reg_id]);
            
            logSystemMessage($conn, "Registration approved for student '$student_name' (Roll: $roll_no) for event '$event_title'. QR code activated.", "success");
        } catch (Exception $e) {
            logSystemMessage($conn, "Approval failed for Registration ID #$reg_id: " . $e->getMessage(), "error");
        }
    } elseif (isset($_POST['action_reject'])) {
        $reg_id = intval($_POST['reg_id']);
        
        try {
            // Fetch details for logging
            $stmt_reg = $conn->prepare("SELECT r.student_name, r.roll_no, e.title FROM registrations r JOIN events e ON r.event_id = e.id WHERE r.id = ?");
            $stmt_reg->execute([$reg_id]);
            $reg_info = $stmt_reg->fetch(PDO::FETCH_ASSOC);
            $student_name = $reg_info ? decryptData($reg_info['student_name']) : "Unknown";
            $roll_no = $reg_info ? decryptData($reg_info['roll_no']) : "Unknown";
            $event_title = $reg_info ? $reg_info['title'] : "Unknown Event";

            $stmt = $conn->prepare("UPDATE registrations SET status = 'rejected', qr_token = NULL, qr_status = 'inactive' WHERE id = ?");
            $stmt->execute([$reg_id]);
            
            logSystemMessage($conn, "Registration rejected for student '$student_name' (Roll: $roll_no) for event '$event_title'.", "success");
        } catch (Exception $e) {
            logSystemMessage($conn, "Rejection failed for Registration ID #$reg_id: " . $e->getMessage(), "error");
        }
    } elseif (isset($_POST['action_toggle_active'])) {
        $event_id = intval($_POST['event_id']);
        $current_status = intval($_POST['current_status']);
        $new_status = ($current_status === 1) ? 0 : 1;
        
        try {
            // Fetch event title
            $stmt_ev = $conn->prepare("SELECT title FROM events WHERE id = ?");
            $stmt_ev->execute([$event_id]);
            $ev_title = $stmt_ev->fetchColumn() ?: "Unknown Event";

            $stmt = $conn->prepare("UPDATE events SET is_active = ? WHERE id = ?");
            $stmt->execute([$new_status, $event_id]);
            $status_str = ($new_status === 1) ? "Active" : "Inactive";
            
            logSystemMessage($conn, "Event '$ev_title' visibility status updated to $status_str.", "success");
        } catch (Exception $e) {
            logSystemMessage($conn, "Failed to update visibility for Event ID #$event_id: " . $e->getMessage(), "error");
        }
    } elseif (isset($_POST['action_toggle_reg'])) {
        $event_id = intval($_POST['event_id']);
        $current_status = trim($_POST['current_status']);
        $new_status = ($current_status === 'open') ? 'closed' : 'open';
        
        try {
            // Fetch event title
            $stmt_ev = $conn->prepare("SELECT title FROM events WHERE id = ?");
            $stmt_ev->execute([$event_id]);
            $ev_title = $stmt_ev->fetchColumn() ?: "Unknown Event";

            $stmt = $conn->prepare("UPDATE events SET reg_status = ? WHERE id = ?");
            $stmt->execute([$new_status, $event_id]);
            
            // Generate report if closing registration
            if ($new_status === 'closed') {
                require_once 'report_helper.php';
                $reportId = archiveEventRegistrationBatch($conn, $event_id);
                if ($reportId) {
                    $_SESSION['success_message'] = "Registration closed. Report #$reportId generated successfully.";
                } else {
                    $_SESSION['success_message'] = "Registration closed (no active registrations to export).";
                }
            } else {
                $_SESSION['success_message'] = "Event '$ev_title' registration opened successfully.";
            }
            
            logSystemMessage($conn, "Event '$ev_title' registration status updated to " . ucfirst($new_status) . ".", "success");
        } catch (Exception $e) {
            logSystemMessage($conn, "Failed to update registration status for Event ID #$event_id: " . $e->getMessage(), "error");
            $_SESSION['error_message'] = "Failed to update registration status: " . $e->getMessage();
        }
    } elseif (isset($_POST['action_add_event'])) {
        $title = trim($_POST['title']);
        $description = trim($_POST['description']);
        $event_date = $_POST['event_date'];
        $location = trim($_POST['location']);
        $event_type = trim($_POST['event_type']);
        $price = 0.00;
        if ($event_type === 'paid') {
            $price = floatval($_POST['price']);
        }
        $image_url = trim($_POST['image_url']);
        
        if (empty($title) || empty($description) || empty($event_date) || empty($location)) {
            logSystemMessage($conn, "Failed to create event: All fields except image URL are required.", "error");
        } elseif ($event_type === 'paid' && $price <= 0) {
            logSystemMessage($conn, "Failed to create event '$title': Ticket price must be greater than $0.00 for paid events.", "error");
        } else {
            try {
                $img = empty($image_url) ? null : $image_url;
                $stmt = $conn->prepare("INSERT INTO events (title, description, event_date, location, price, image_url, is_active, reg_status) VALUES (?, ?, ?, ?, ?, ?, 1, 'open')");
                $stmt->execute([$title, $description, $event_date, $location, $price, $img]);
                
                logSystemMessage($conn, "New event '$title' created successfully (Location: '$location', Type: '$event_type', Price: $$price).", "success");
            } catch (Exception $e) {
                logSystemMessage($conn, "Failed to create event '$title': " . $e->getMessage(), "error");
            }
        }
    } elseif (isset($_POST['action_add_helper'])) {
        $name = trim($_POST['helper_name']);
        if (empty($name)) {
            logSystemMessage($conn, "Failed to register helper: Helper name is required.", "error");
        } else {
            try {
                $helper_key = bin2hex(random_bytes(16));
                $stmt = $conn->prepare("INSERT INTO helpers (name, helper_key) VALUES (?, ?)");
                $stmt->execute([$name, $helper_key]);
                
                logSystemMessage($conn, "Helper '$name' registered successfully with an access passcode.", "success");
            } catch (Exception $e) {
                logSystemMessage($conn, "Failed to register helper '$name': " . $e->getMessage(), "error");
            }
        }
    } elseif (isset($_POST['action_delete_helper'])) {
        $helper_id = intval($_POST['helper_id']);
        try {
            // Fetch helper name before deleting
            $stmt_helper = $conn->prepare("SELECT name FROM helpers WHERE id = ?");
            $stmt_helper->execute([$helper_id]);
            $helper_name = $stmt_helper->fetchColumn() ?: "Unknown Helper";

            $stmt = $conn->prepare("DELETE FROM helpers WHERE id = ?");
            $stmt->execute([$helper_id]);
            
            logSystemMessage($conn, "Helper '$helper_name' removed successfully. Access key revoked.", "success");
        } catch (Exception $e) {
            logSystemMessage($conn, "Failed to remove helper: " . $e->getMessage(), "error");
        }
    } elseif (isset($_POST['action_clear_logs'])) {
        try {
            $conn->exec("TRUNCATE TABLE system_logs");
            logSystemMessage($conn, "System audit logs cleared by administrator.", "success");
        } catch (Exception $e) {
            // Suppress or handle
        }
    } elseif (isset($_POST['action_upload_template'])) {
        $event_id = intval($_POST['event_id']);
        try {
            if (isset($_FILES['template_image']) && $_FILES['template_image']['error'] === UPLOAD_ERR_OK) {
                $fileTmpPath = $_FILES['template_image']['tmp_name'];
                $fileName = $_FILES['template_image']['name'];
                $fileExtension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
                
                $allowedExtensions = ['png', 'jpg', 'jpeg'];
                if (in_array($fileExtension, $allowedExtensions)) {
                    $uploadDir = __DIR__ . '/uploads/templates/';
                    if (!file_exists($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }
                    
                    $destPath = $uploadDir . 'template_' . $event_id . '.' . $fileExtension;
                    if (move_uploaded_file($fileTmpPath, $destPath)) {
                        // Store the relative path in the database
                        $relativeDestPath = 'uploads/templates/template_' . $event_id . '.' . $fileExtension;
                        $stmt = $conn->prepare("UPDATE events SET certificate_template = ? WHERE id = ?");
                        $stmt->execute([$relativeDestPath, $event_id]);
                        
                        logSystemMessage($conn, "Uploaded certificate template for Event ID #$event_id.", "success");
                        $_SESSION['success_message'] = "Certificate template uploaded successfully.";
                    } else {
                        $_SESSION['error_message'] = "Failed to move uploaded template file.";
                    }
                } else {
                    $_SESSION['error_message'] = "Invalid file type. Only PNG, JPG, and JPEG are allowed.";
                }
            } else {
                $_SESSION['error_message'] = "No file uploaded or upload error occurred.";
            }
        } catch (Exception $e) {
            logSystemMessage($conn, "Failed to upload template for Event ID #$event_id: " . $e->getMessage(), "error");
            $_SESSION['error_message'] = "Upload failed: " . $e->getMessage();
        }
    } elseif (isset($_POST['action_generate_certs'])) {
        $event_id = intval($_POST['event_id']);
        try {
            // Fetch event info
            $stmt_event = $conn->prepare("SELECT * FROM events WHERE id = ?");
            $stmt_event->execute([$event_id]);
            $event = $stmt_event->fetch(PDO::FETCH_ASSOC);
            
            if (!$event) {
                throw new Exception("Event not found.");
            }
            if (empty($event['certificate_template'])) {
                throw new Exception("No certificate template uploaded for this event.");
            }
            
            // Fetch all approved registrations
            $stmt_regs = $conn->prepare("SELECT r.*, u.name as student_name, u.email as student_email FROM registrations r JOIN users u ON r.user_id = u.id WHERE r.event_id = ? AND r.status = 'approved'");
            $stmt_regs->execute([$event_id]);
            $approved_regs = $stmt_regs->fetchAll(PDO::FETCH_ASSOC);
            
            if (empty($approved_regs)) {
                $_SESSION['error_message'] = "No approved registrations found for this event.";
            } else {
                $count = 0;
                
                $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $base_url = $protocol . '://' . $host . '/event';
                
                $webTemplateUrl = $base_url . '/' . $event['certificate_template'];
                
                foreach ($approved_regs as $reg) {
                    // 1. Ensure QR token exists
                    $qr_token = $reg['qr_token'];
                    if (empty($qr_token)) {
                        $qr_token = bin2hex(random_bytes(16));
                        $stmt_tok = $conn->prepare("UPDATE registrations SET qr_token = ? WHERE id = ?");
                        $stmt_tok->execute([$qr_token, $reg['id']]);
                    }
                    
                    // 2. Generate QR code SVG file locally
                    $qr_local_path = str_replace('\\', '/', __DIR__ . '/uploads/qrcodes/qr_' . $qr_token . '.svg');
                    $qr_relative_path = 'uploads/qrcodes/qr_' . $qr_token . '.svg';
                    generateQrCode($qr_token, $qr_local_path);
                    
                    // Build web URL for QR code to pass to Dompdf
                    $webQrUrl = $base_url . '/' . $qr_relative_path;
                    
                    // 3. Generate Certificate PDF file
                    $certId = 'EVT/' . $event_id . '/' . $reg['id'];
                    $user_data = [
                        'id' => $reg['user_id'],
                        'name' => $reg['student_name'],
                        'batch' => $reg['batch'],
                        'role' => $reg['event_role']
                    ];
                    $event_data = [
                        'id' => $event_id,
                        'title' => $event['title'],
                        'event_date' => $event['event_date'],
                        'location' => $event['location']
                    ];
                    
                    // Call creator helper with local file paths
                    $template_local_path = str_replace('\\', '/', __DIR__ . '/' . $event['certificate_template']);
                    $pdf_absolute_path = createCertificatePdf($user_data, $event_data, $qr_local_path, $template_local_path, $certId);
                    
                    if ($pdf_absolute_path) {
                        $pdf_relative_path = str_replace(__DIR__ . '/', '', $pdf_absolute_path);
                        storeCertificate($reg['user_id'], $event_id, $pdf_relative_path, $qr_relative_path);
                        $count++;
                    }
                }
                
                // Mark event as published
                $stmt_pub = $conn->prepare("UPDATE events SET certificate_published = 1 WHERE id = ?");
                $stmt_pub->execute([$event_id]);
                
                logSystemMessage($conn, "Generated $count certificates for Event ID #$event_id.", "success");
                $_SESSION['success_message'] = "Successfully generated and published $count certificates.";
            }
        } catch (Exception $e) {
            logSystemMessage($conn, "Failed to generate certificates for Event ID #$event_id: " . $e->getMessage(), "error");
            $_SESSION['error_message'] = "Generation failed: " . $e->getMessage();
        }
    } elseif (isset($_POST['action_delete_certificate'])) {
        $cert_id = intval($_POST['cert_id']);
        try {
            $stmt = $conn->prepare("SELECT * FROM certificates WHERE id = ?");
            $stmt->execute([$cert_id]);
            $cert = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($cert) {
                // Delete files
                $pdf_full_path = __DIR__ . '/' . $cert['pdf_path'];
                $qr_full_path = __DIR__ . '/' . $cert['qr_path'];
                if (file_exists($pdf_full_path)) {
                    @unlink($pdf_full_path);
                }
                if (file_exists($qr_full_path)) {
                    @unlink($qr_full_path);
                }
                // Delete DB record
                $stmt_del = $conn->prepare("DELETE FROM certificates WHERE id = ?");
                $stmt_del->execute([$cert_id]);
                
                logSystemMessage($conn, "Deleted certificate ID #$cert_id.", "success");
                $_SESSION['success_message'] = "Certificate deleted successfully.";
            } else {
                $_SESSION['error_message'] = "Certificate not found.";
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Delete failed: " . $e->getMessage();
        }
    } elseif (isset($_POST['action_delete_all_certs'])) {
        $event_id = intval($_POST['event_id']);
        try {
            $stmt = $conn->prepare("SELECT * FROM certificates WHERE event_id = ?");
            $stmt->execute([$event_id]);
            $certs = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $count = 0;
            foreach ($certs as $cert) {
                $pdf_full_path = __DIR__ . '/' . $cert['pdf_path'];
                $qr_full_path = __DIR__ . '/' . $cert['qr_path'];
                if (file_exists($pdf_full_path)) {
                    @unlink($pdf_full_path);
                }
                if (file_exists($qr_full_path)) {
                    @unlink($qr_full_path);
                }
                $count++;
            }
            
            $stmt_del = $conn->prepare("DELETE FROM certificates WHERE event_id = ?");
            $stmt_del->execute([$event_id]);
            
            $stmt_pub = $conn->prepare("UPDATE events SET certificate_published = 0 WHERE id = ?");
            $stmt_pub->execute([$event_id]);
            
            logSystemMessage($conn, "Deleted all $count generated certificates for Event ID #$event_id.", "success");
            $_SESSION['success_message'] = "Deleted all $count generated certificates for this event.";
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Failed to delete certificates: " . $e->getMessage();
        }
    } elseif (isset($_POST['action_delete_report'])) {
        $report_id = intval($_POST['report_id']);
        try {
            $stmt = $conn->prepare("SELECT * FROM event_reports WHERE id = ?");
            $stmt->execute([$report_id]);
            $report = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($report) {
                // Delete files
                $excel_full_path = __DIR__ . '/' . $report['excel_path'];
                $pdf_full_path = __DIR__ . '/' . $report['pdf_path'];
                if (file_exists($excel_full_path)) {
                    @unlink($excel_full_path);
                }
                if (file_exists($pdf_full_path)) {
                    @unlink($pdf_full_path);
                }
                // Delete DB record
                $stmt_del = $conn->prepare("DELETE FROM event_reports WHERE id = ?");
                $stmt_del->execute([$report_id]);
                
                logSystemMessage($conn, "Deleted registration report ID #$report_id.", "success");
                $_SESSION['success_message'] = "Registration report deleted successfully.";
            } else {
                $_SESSION['error_message'] = "Report not found.";
            }
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Failed to delete report: " . $e->getMessage();
        }
    } elseif (isset($_POST['action_add_activity'])) {
        $event_id = intval($_POST['event_id']);
        $title = trim($_POST['title']);
        $activity_type = trim($_POST['activity_type']);
        $description = trim($_POST['description']);
        $max_teams = intval($_POST['max_teams'] ?? 0);
        
        if (empty($title) || empty($activity_type)) {
            $_SESSION['error_message'] = "Activity title and type are required.";
        } else {
            try {
                $stmt = $conn->prepare("INSERT INTO activities (event_id, title, activity_type, description, max_teams) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$event_id, $title, $activity_type, $description, $max_teams]);
                
                logSystemMessage($conn, "New activity '$title' ($activity_type) added for Event ID #$event_id.", "success");
                $_SESSION['success_message'] = "Activity added successfully.";
            } catch (Exception $e) {
                $_SESSION['error_message'] = "Failed to add activity: " . $e->getMessage();
            }
        }
    } elseif (isset($_POST['action_delete_activity'])) {
        $activity_id = intval($_POST['activity_id']);
        try {
            $stmt = $conn->prepare("DELETE FROM activities WHERE id = ?");
            $stmt->execute([$activity_id]);
            
            logSystemMessage($conn, "Deleted activity ID #$activity_id.", "success");
            $_SESSION['success_message'] = "Activity deleted successfully.";
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Failed to delete activity: " . $e->getMessage();
        }
    } elseif (isset($_POST['action_approve_activity_reg'])) {
        $act_id = intval($_POST['activity_id']);
        $team_name = $_POST['team_name'];
        $team_leader_reg_id = intval($_POST['team_leader_reg_id']);
        
        try {
            if (!empty($team_name)) {
                $stmt = $conn->prepare("UPDATE activity_registrations SET status = 'approved' WHERE activity_id = ? AND team_name = ? AND team_leader_reg_id = ?");
                $stmt->execute([$act_id, $team_name, $team_leader_reg_id]);
            } else {
                $reg_id = intval($_POST['registration_id']);
                $stmt = $conn->prepare("UPDATE activity_registrations SET status = 'approved' WHERE activity_id = ? AND registration_id = ?");
                $stmt->execute([$act_id, $reg_id]);
            }
            logSystemMessage($conn, "Approved registration for activity ID #$act_id.", "success");
            $_SESSION['success_message'] = "Activity registration approved successfully.";
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Failed to approve: " . $e->getMessage();
        }
    } elseif (isset($_POST['action_reject_activity_reg'])) {
        $act_id = intval($_POST['activity_id']);
        $team_name = $_POST['team_name'];
        $team_leader_reg_id = intval($_POST['team_leader_reg_id']);
        
        try {
            if (!empty($team_name)) {
                $stmt = $conn->prepare("UPDATE activity_registrations SET status = 'rejected' WHERE activity_id = ? AND team_name = ? AND team_leader_reg_id = ?");
                $stmt->execute([$act_id, $team_name, $team_leader_reg_id]);
            } else {
                $reg_id = intval($_POST['registration_id']);
                $stmt = $conn->prepare("UPDATE activity_registrations SET status = 'rejected' WHERE activity_id = ? AND registration_id = ?");
                $stmt->execute([$act_id, $reg_id]);
            }
            logSystemMessage($conn, "Rejected registration for activity ID #$act_id.", "success");
            $_SESSION['success_message'] = "Activity registration rejected.";
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Failed to reject: " . $e->getMessage();
        }
    } elseif (isset($_POST['action_delete_activity_reg'])) {
        $act_id = intval($_POST['activity_id']);
        $team_name = $_POST['team_name'];
        $team_leader_reg_id = intval($_POST['team_leader_reg_id']);
        
        try {
            if (!empty($team_name)) {
                $stmt = $conn->prepare("DELETE FROM activity_registrations WHERE activity_id = ? AND team_name = ? AND team_leader_reg_id = ?");
                $stmt->execute([$act_id, $team_name, $team_leader_reg_id]);
            } else {
                $reg_id = intval($_POST['registration_id']);
                $stmt = $conn->prepare("DELETE FROM activity_registrations WHERE activity_id = ? AND registration_id = ?");
                $stmt->execute([$act_id, $reg_id]);
            }
            logSystemMessage($conn, "Deleted registration entry for activity ID #$act_id.", "success");
            $_SESSION['success_message'] = "Deleted activity registration entry.";
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Failed to delete: " . $e->getMessage();
        }
    }

    // Redirect in all cases after processing POST to prevent form resubmission
    header('Location: admin_dashboard.php');
    exit();
}

// Fetch stats
$total_users_stmt = $conn->query("SELECT COUNT(*) FROM users WHERE role = 'student'");
$total_students = $total_users_stmt->fetchColumn();

$total_all_users_stmt = $conn->query("SELECT COUNT(*) FROM users");
$total_all_users = $total_all_users_stmt->fetchColumn();

$total_regs_stmt = $conn->query("SELECT COUNT(*) FROM registrations");
$total_registrations = $total_regs_stmt->fetchColumn();

$approved_regs_stmt = $conn->query("SELECT COUNT(*) FROM registrations WHERE status = 'approved'");
$approved_registrations = $approved_regs_stmt->fetchColumn();

$pending_regs_stmt = $conn->query("SELECT COUNT(*) FROM registrations WHERE status = 'pending'");
$pending_registrations = $pending_regs_stmt->fetchColumn();

// Fetch registrations
$registrations_stmt = $conn->query("
    SELECT r.*, e.title as event_title 
    FROM registrations r 
    JOIN events e ON r.event_id = e.id 
    ORDER BY r.created_at DESC
");
$registrations = $registrations_stmt->fetchAll(PDO::FETCH_ASSOC);

// Decrypt sensitive student fields for display
foreach ($registrations as &$r) {
    $r['student_name']    = decryptData($r['student_name']);
    $r['roll_no']         = decryptData($r['roll_no']);
    $r['batch']           = decryptData($r['batch']);
    $r['food_preference'] = decryptData($r['food_preference']);
}
unset($r);

// Fetch all events with registration count for Event Manager
$admin_events_stmt = $conn->query("
    SELECT e.*, COUNT(r.id) as total_regs 
    FROM events e 
    LEFT JOIN registrations r ON e.id = r.event_id 
    GROUP BY e.id 
    ORDER BY e.event_date ASC
");
$admin_events = $admin_events_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch all helpers for Helper Manager
try {
    $helpers_stmt = $conn->query("SELECT * FROM helpers ORDER BY created_at DESC");
    $helpers = $helpers_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $helpers = [];
}

// Fetch 5 most recent logs for Dashboard Overview
try {
    $recent_logs_stmt = $conn->query("SELECT * FROM system_logs ORDER BY created_at DESC LIMIT 5");
    $recent_logs = $recent_logs_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recent_logs = [];
}

// Fetch all feedbacks for Feedback Management
try {
    $feedbacks_stmt = $conn->query("
        SELECT f.*, e.title as event_title 
        FROM feedbacks f 
        JOIN events e ON f.event_id = e.id 
        ORDER BY f.created_at DESC
    ");
    $feedbacks = $feedbacks_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $feedbacks = [];
}

// Fetch average ratings per event
try {
    $avg_ratings_stmt = $conn->query("
        SELECT e.id, e.title, AVG(f.rating) as avg_rating, COUNT(f.id) as total_feedbacks 
        FROM events e 
        LEFT JOIN feedbacks f ON e.id = f.event_id 
        GROUP BY e.id 
        ORDER BY e.title ASC
    ");
    $avg_ratings = $avg_ratings_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $avg_ratings = [];
}
// Fetch all generated reports
try {
    $reports_stmt = $conn->query("
        SELECT er.*, e.title as event_title 
        FROM event_reports er 
        JOIN events e ON er.event_id = e.id 
        ORDER BY er.generated_at DESC
    ");
    $reports = $reports_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $reports = [];
}

// Fetch all activities
try {
    $all_activities_stmt = $conn->query("
        SELECT a.*, e.title as event_title 
        FROM activities a 
        JOIN events e ON a.event_id = e.id 
        ORDER BY e.event_date DESC, a.title ASC
    ");
    $all_activities = $all_activities_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $all_activities = [];
}

// Fetch all activity registrations
try {
    $act_regs_stmt = $conn->query("
        SELECT ar.*, a.title as activity_title, a.activity_type, e.title as event_title,
               r.student_name, r.roll_no, r.batch, r.stream
        FROM activity_registrations ar
        JOIN activities a ON ar.activity_id = a.id
        JOIN events e ON a.event_id = e.id
        JOIN registrations r ON ar.registration_id = r.id
        ORDER BY ar.created_at DESC
    ");
    $act_regs = $act_regs_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Decrypt names and rolls
    foreach ($act_regs as &$ar) {
        $ar['student_name'] = decryptData($ar['student_name']);
        $ar['roll_no'] = decryptData($ar['roll_no']);
        $ar['batch'] = decryptData($ar['batch']);
    }
    unset($ar);
    
    // Group them by activity and team for clean display
    $grouped_act_regs = [];
    foreach ($act_regs as $ar) {
        $act_key = $ar['activity_id'];
        $team_key = $ar['team_name'] ?: 'solo_' . $ar['registration_id'];
        
        if (!isset($grouped_act_regs[$act_key])) {
            $grouped_act_regs[$act_key] = [
                'activity_title' => $ar['activity_title'],
                'activity_type' => $ar['activity_type'],
                'event_title' => $ar['event_title'],
                'teams' => []
            ];
        }
        
        if (!isset($grouped_act_regs[$act_key]['teams'][$team_key])) {
            $grouped_act_regs[$act_key]['teams'][$team_key] = [
                'team_name' => $ar['team_name'],
                'team_leader_reg_id' => $ar['team_leader_reg_id'],
                'status' => $ar['status'],
                'track_link' => $ar['track_link'],
                'members' => []
            ];
        }
        
        $grouped_act_regs[$act_key]['teams'][$team_key]['members'][] = [
            'registration_id' => $ar['registration_id'],
            'student_name' => $ar['student_name'],
            'roll_no' => $ar['roll_no'],
            'batch' => $ar['batch'],
            'stream' => $ar['stream']
        ];
    }
} catch (PDOException $e) {
    $grouped_act_regs = [];
}

function renderFeedbackStars($rating) {
    $output = '';
    for ($i = 1; $i <= 5; $i++) {
        if ($i <= $rating) {
            $output .= '<span style="color: #FFC107; font-size: 1.1rem;">★</span>';
        } else {
            $output .= '<span style="color: #D1D9E6; font-size: 1.1rem;">★</span>';
        }
    }
    return $output;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard | Nexus</title>
    <link rel="stylesheet" href="css/style.css?v=1.6">
    <script src="js/theme.js?v=1.6"></script>
    <style>
        /* Admin Dashboard Sidebar Layout Overrides */
        body {
            margin: 0;
            padding: 0;
            overflow-x: hidden;
            display: block;
        }

        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background:
                radial-gradient(ellipse at 0% 0%, rgba(99, 102, 241, 0.06) 0%, transparent 50%),
                radial-gradient(ellipse at 100% 100%, rgba(16, 185, 129, 0.04) 0%, transparent 50%),
                radial-gradient(ellipse at 50% 50%, rgba(245, 158, 11, 0.03) 0%, transparent 60%);
            pointer-events: none;
            z-index: -1;
        }

        .admin-layout {
            display: flex;
            min-height: 100vh;
            background-color: var(--bg-main);
            color: var(--text-primary);
        }

        .admin-sidebar {
            width: 260px;
            background: var(--bg-card-glass);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border-right: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0;
            bottom: 0;
            left: 0;
            z-index: 100;
            padding: 1.5rem 1rem;
            box-sizing: border-box;
            transition: var(--transition);
        }

        .sidebar-brand {
            font-size: 1.35rem;
            font-weight: 800;
            font-family: var(--font-heading);
            margin-bottom: 2rem;
            color: var(--text-primary);
            text-align: center;
        }
        .sidebar-brand span {
            color: var(--primary);
        }

        .sidebar-profile {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 1rem;
            background: rgba(255, 255, 255, 0.02);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            margin-bottom: 2rem;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.02);
            transition: var(--transition);
        }
        .sidebar-profile:hover {
            border-color: var(--primary);
            box-shadow: 0 0 15px var(--primary-glow);
        }
        .sidebar-profile .profile-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: var(--primary-glow);
            border: 1px solid var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            flex-shrink: 0;
        }
        .sidebar-profile .profile-name {
            font-weight: 700;
            font-size: 0.9rem;
            color: var(--text-primary);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 130px;
        }
        .sidebar-profile .profile-role {
            font-size: 0.75rem;
            color: var(--text-secondary);
        }

        .sidebar-nav {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
            flex-grow: 1;
        }

        .sidebar-nav .sidebar-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            background: transparent;
            border: 1px solid transparent;
            color: var(--text-secondary);
            padding: 0.75rem 1rem;
            border-radius: var(--radius-sm);
            font-family: var(--font-body);
            font-size: 0.95rem;
            font-weight: 600;
            text-decoration: none;
            cursor: pointer;
            text-align: left;
            transition: var(--transition);
            width: 100%;
            box-sizing: border-box;
            position: relative;
            overflow: hidden;
        }

        .sidebar-nav .sidebar-link:hover, 
        .sidebar-nav .sidebar-link.active {
            background: var(--primary-glow);
            border-color: var(--primary);
            color: var(--primary);
            padding-left: 1.25rem;
        }
        
        .sidebar-nav .sidebar-link::before {
            content: '';
            position: absolute;
            left: 0;
            top: 20%;
            height: 60%;
            width: 3px;
            background: var(--primary);
            border-radius: 0 4px 4px 0;
            transform: scaleY(0);
            transition: transform 0.25s ease;
        }
        .sidebar-nav .sidebar-link.active::before {
            transform: scaleY(1);
        }

        .sidebar-divider {
            border: none;
            border-top: 1px solid var(--border-color);
            margin: 1rem 0;
        }

        .sidebar-footer {
            padding-top: 1rem;
            border-top: 1px solid var(--border-color);
        }

        /* Main Content area */
        .admin-main {
            margin-left: 260px;
            flex-grow: 1;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            box-sizing: border-box;
            background: var(--bg-main);
            transition: var(--transition);
            position: relative;
            z-index: 1;
        }

        .admin-header {
            background: var(--bg-card-glass);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border-bottom: 1px solid var(--border-color);
            padding: 1.25rem 2rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: sticky;
            top: 0;
            z-index: 90;
        }

        .admin-page-title {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 800;
            font-family: var(--font-heading);
            color: var(--text-primary);
        }

        .admin-content-inner {
            padding: 2rem;
            flex-grow: 1;
            width: 100%;
            margin: 0 auto;
            box-sizing: border-box;
        }

        /* Data table container layout fix */
        .table-responsive {
            width: 100%;
            overflow-x: auto;
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            background: var(--bg-card-glass);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.02);
        }
        
        table.data-table th {
            font-family: monospace;
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-secondary);
            border-bottom: 1.5px solid var(--border-color);
        }

        /* Responsive adjustments */
        @media(max-width: 992px) {
            .admin-sidebar {
                width: 70px;
                padding: 1.5rem 0.5rem;
                align-items: center;
            }
            .sidebar-brand {
                font-size: 1.15rem;
                margin-bottom: 1.5rem;
            }
            .sidebar-brand span {
                display: none;
            }
            .sidebar-profile {
                padding: 0.5rem;
                margin-bottom: 1.5rem;
                justify-content: center;
                border: none;
                background: transparent;
            }
            .sidebar-profile .profile-info {
                display: none;
            }
            .sidebar-nav .sidebar-link {
                padding: 0.75rem;
                justify-content: center;
            }
            .sidebar-nav .sidebar-link span:last-child {
                display: none; /* Hide text, keep icon */
            }
            .sidebar-divider {
                width: 100%;
            }
            .admin-main {
                margin-left: 70px;
            }
            .admin-header {
                padding: 1rem 1.5rem;
            }
            .admin-content-inner {
                padding: 1.5rem 1rem;
            }
        }

        /* Activity Timeline Styles */
        .activity-timeline {
            display: flex;
            flex-direction: column;
            gap: 1.25rem;
            position: relative;
            margin-top: 1.5rem;
        }

        .activity-item {
            display: flex;
            gap: 1rem;
            position: relative;
        }

        .activity-item:not(:last-child)::after {
            content: '';
            position: absolute;
            left: 20px;
            top: 40px;
            bottom: -20px;
            width: 2px;
            background: var(--border-color);
            z-index: 1;
        }

        .activity-badge {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1rem;
            flex-shrink: 0;
            z-index: 2;
            border: 1px solid var(--border-color);
            background: var(--bg-input);
            box-shadow: 0 4px 10px rgba(0,0,0,0.05);
            transition: var(--transition);
        }
        .activity-item:hover .activity-badge {
            transform: scale(1.1);
        }

        .activity-content {
            background: var(--bg-card-glass);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid var(--border-color);
            padding: 1rem 1.5rem;
            border-radius: var(--radius-md);
            flex-grow: 1;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.02);
            transition: var(--transition);
        }
        .activity-item:hover .activity-content {
            border-color: var(--primary);
            box-shadow: 0 8px 24px var(--primary-glow);
            transform: translateX(3px);
        }

        .activity-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.5rem;
        }

        .activity-time {
            font-size: 0.75rem;
            color: var(--text-muted);
            font-family: monospace;
        }

        .activity-message {
            font-size: 0.9rem;
            color: var(--text-primary);
            line-height: 1.5;
            margin: 0;
        }
        
        /* Glassmorphic Metrics Card Upgrade */
        .stat-card {
            background: var(--bg-card-glass) !important;
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            transition: var(--transition);
            box-shadow: 0 4px 15px rgba(0,0,0,0.02);
            position: relative;
            overflow: hidden;
        }
        .stat-card::after {
            content: '';
            position: absolute;
            bottom: 0; left: 0; right: 0;
            height: 3px;
            background: var(--primary);
            transition: var(--transition);
        }
        .stat-card:hover {
            transform: translateY(-4px);
            border-color: var(--primary) !important;
            box-shadow: 0 8px 24px var(--primary-glow);
        }
        
        .stat-card .stat-title {
            font-family: var(--font-body);
            font-size: 0.8rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--text-muted);
            font-weight: 700;
        }
        
        .stat-card .stat-value {
            font-size: 2.5rem;
            font-weight: 800;
            font-family: var(--font-heading);
            color: var(--text-primary);
            line-height: 1.2;
            margin-top: 0.25rem;
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <!-- Sidebar Panel on the Left -->
        <aside class="admin-sidebar">
            <div class="logo-container" style="justify-content: center; margin-bottom: 2rem; padding: 0 0.5rem;">
                <img src="images/nexus_logo.png" alt="Nexus Logo" class="logo-img" style="height: 32px;">
                <span class="logo-text" style="font-size: 1.35rem; color: var(--text-primary);">Nexus <span style="font-weight: 400; font-size: 0.9rem; color: var(--text-secondary);">Admin</span></span>
            </div>
            
            <div class="sidebar-profile">
                <div class="profile-avatar" style="overflow: hidden; padding: 0; background: none; border: 1px solid var(--border-color);">
                    <img src="<?php echo htmlspecialchars($gravatar_url); ?>" alt="Profile Picture" style="width: 100%; height: 100%; object-fit: cover;">
                </div>
                <div class="profile-info">
                    <div class="profile-name"><?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Administrator'); ?></div>
                    <div class="profile-role">System Control</div>
                </div>
            </div>
            
            <nav class="sidebar-nav">
                <button class="sidebar-link active" onclick="switchTab('dashboard-overview', this)">
                    <i class="bi bi-speedometer2"></i> <span>Dashboard Overview</span>
                </button>
                <button class="sidebar-link" onclick="switchTab('manage-regs', this)">
                    <i class="bi bi-person-check-fill"></i> <span>Registration Manager</span>
                </button>
                <button class="sidebar-link" onclick="switchTab('manage-helpers', this)">
                    <i class="bi bi-people-fill"></i> <span>Helper Manager</span>
                </button>
                <button class="sidebar-link" onclick="switchTab('manage-events', this)">
                    <i class="bi bi-calendar-event"></i> <span>Manage Events</span>
                </button>
                <button class="sidebar-link" onclick="switchTab('manage-activities', this)">
                    <i class="bi bi-music-note-beamed"></i> <span>Activities Manager</span>
                </button>
                <button class="sidebar-link" onclick="switchTab('feedback-mgmt', this)">
                    <i class="bi bi-chat-right-text"></i> <span>Feedback Management</span>
                </button>
                <button class="sidebar-link" onclick="switchTab('system-logs', this)">
                    <i class="bi bi-journal-text"></i> <span>System Logs</span>
                </button>
                <button class="sidebar-link" onclick="switchTab('cert-generator', this)">
                    <i class="bi bi-patch-check-fill"></i> <span>Certificate Generator</span>
                </button>
                <button class="sidebar-link" onclick="switchTab('export-reports', this)">
                    <i class="bi bi-file-earmark-excel-fill"></i> <span>Export Reports</span>
                </button>
                
                <hr class="sidebar-divider">
                
                <a href="index.php" class="sidebar-link">
                    <i class="bi bi-house-door-fill"></i> <span>View Homepage</span>
                </a>
                <a href="logout.php" class="sidebar-link" style="color: var(--danger);">
                    <i class="bi bi-box-arrow-left"></i> <span>Logout</span>
                </a>
            </nav>
            
            <div class="sidebar-footer">
                <button class="theme-toggle-btn" aria-label="Toggle Theme" style="margin: 0 auto; display: block; border-radius: 50%;">🌙</button>
            </div>
        </aside>
        
        <!-- Main Content Area on the Right -->
        <main class="admin-main">
            <!-- Top Header -->
            <header class="admin-header">
                <h1 class="admin-page-title" id="admin-current-tab-title">Dashboard Overview</h1>
            </header>
            
            <div class="admin-content-inner">
                <!-- Tab: Dashboard Overview -->
                <div id="tab-dashboard-overview" class="tab-pane active">
                    <!-- Futuristic Welcome Banner -->
                    <div class="welcome-banner">
                        <div class="welcome-text">
                            <h2 id="welcome-greeting">Welcome, <?php echo htmlspecialchars($_SESSION['user_name'] ?? 'Administrator'); ?>!</h2>
                            <p>Welcome to your Nexus administrative dashboard. Control portal events, verify registrations, and mark attendance.</p>
                        </div>
                        <div style="font-size: 3.5rem; filter: drop-shadow(0 4px 8px var(--primary-glow));">🛡️</div>
                    </div>

                    <!-- Quick Stats Overview -->
                    <div style="margin-bottom: 2.5rem;">
                        <h3 style="font-size: 1.05rem; color: var(--text-secondary); margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.5rem; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 700;">
                            <span>🌐</span> Website User Registrations
                        </h3>
                        <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); margin-bottom: 1.5rem; gap: 1.5rem;">
                            <div class="stat-card" style="border-left: 4px solid var(--primary);">
                                <span class="stat-title">Registered Students</span>
                                <span class="stat-value"><?php echo $total_students; ?></span>
                            </div>
                            <div class="stat-card" style="border-left: 4px solid var(--secondary);">
                                <span class="stat-title">Total Accounts (Incl. Admins)</span>
                                <span class="stat-value"><?php echo $total_all_users; ?></span>
                            </div>
                        </div>

                        <h3 style="font-size: 1.05rem; color: var(--text-secondary); margin-bottom: 0.75rem; display: flex; align-items: center; gap: 0.5rem; text-transform: uppercase; letter-spacing: 0.05em; font-weight: 700;">
                            <span>🎫</span> Event Registrations & Access Tickets
                        </h3>
                        <div class="stats-grid" style="gap: 1.5rem; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));">
                            <div class="stat-card">
                                <span class="stat-title">Total Registrations</span>
                                <span class="stat-value"><?php echo $total_registrations; ?></span>
                            </div>
                            <div class="stat-card">
                                <span class="stat-title">Approved Registrations</span>
                                <span class="stat-value" style="color: var(--success);"><?php echo $approved_registrations; ?></span>
                            </div>
                            <div class="stat-card">
                                <span class="stat-title">Pending Approvals</span>
                                <span class="stat-value" style="color: var(--warning);"><?php echo $pending_registrations; ?></span>
                            </div>
                        </div>
                    </div>

                    <!-- Recent System Activity Logs Feed -->
                    <div class="glass-panel" style="padding: 2rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.5rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.75rem;">
                            <h3 style="margin: 0; font-size: 1.25rem; display: flex; align-items: center; gap: 0.5rem;">
                                <span>⚡</span> Recent System Activity
                            </h3>
                            <button class="btn btn-secondary" style="font-size: 0.8rem; padding: 0.4rem 0.8rem;" onclick="switchTab('system-logs')">
                                View All Logs ➔
                            </button>
                        </div>

                        <?php if (empty($recent_logs)): ?>
                            <p style="text-align: center; color: var(--text-muted); font-size: 0.9rem; padding: 1.5rem 0;">No recent activity logs.</p>
                        <?php else: ?>
                            <div class="activity-timeline">
                                <?php foreach ($recent_logs as $log): ?>
                                    <?php
                                    $dot_color = 'var(--primary)';
                                    $dot_glow = 'var(--primary-glow)';
                                    $dot_icon = 'ℹ️';
                                    if ($log['log_type'] === 'success') {
                                        $dot_color = 'var(--success)';
                                        $dot_glow = 'var(--success-glow)';
                                        $dot_icon = '✅';
                                    } elseif ($log['log_type'] === 'error') {
                                        $dot_color = 'var(--danger)';
                                        $dot_glow = 'rgba(220, 53, 69, 0.15)';
                                        $dot_icon = '⚠️';
                                    }
                                    ?>
                                    <div class="activity-item">
                                        <div class="activity-badge" style="background: <?php echo $dot_glow; ?>; color: <?php echo $dot_color; ?>;">
                                            <?php echo $dot_icon; ?>
                                        </div>
                                        <div class="activity-content">
                                            <div class="activity-header">
                                                <span class="activity-type" style="color: <?php echo $dot_color; ?>; font-weight: 700; text-transform: uppercase; font-size: 0.75rem; letter-spacing: 0.05em;">
                                                    <?php echo htmlspecialchars($log['log_type']); ?>
                                                </span>
                                                <span class="activity-time">
                                                    <?php echo date('M d, H:i:s', strtotime($log['created_at'])); ?>
                                                </span>
                                            </div>
                                            <p class="activity-message"><?php echo htmlspecialchars($log['message']); ?></p>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Tab: Registration Manager -->
                <div id="tab-manage-regs" class="tab-pane">
                    <div class="glass-panel" style="padding: 2rem;">
                        <h2>Verify Registration Requests</h2>
                        <p style="color: var(--text-secondary); margin-bottom: 1.5rem;">Review payment receipts, details, and issue QR access tickets.</p>
                        
                        <div style="display: flex; gap: 1rem; align-items: center; margin-bottom: 1.5rem; justify-content: space-between; flex-wrap: wrap;">
                            <div class="search-box-wrapper" style="max-width: 380px;">
                                <span class="search-icon">🔍</span>
                                <input type="text" id="reg-search" class="search-input" placeholder="Search student, roll, event or status..." onkeyup="filterRegistrations()">
                            </div>
                        </div>
                        
                        <?php if (empty($registrations)): ?>
                            <div style="text-align: center; padding: 4rem 1rem; color: var(--text-muted);">
                                <span style="font-size: 3rem;">📋</span>
                                <h3 style="margin-top: 1rem; color: var(--text-secondary);">No registrations submitted</h3>
                                <p style="font-size: 0.9rem; margin-top: 0.5rem;">Student requests will appear here once submitted.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Student & Event</th>
                                            <th>Details</th>
                                            <th>Food & Role</th>
                                            <th>Receipt Proof</th>
                                            <th>Status</th>
                                            <th>Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($registrations as $r): ?>
                                            <tr>
                                                <td>
                                                    <strong style="color: var(--text-primary);"><?php echo htmlspecialchars($r['student_name']); ?></strong>
                                                    <div style="font-size: 0.8rem; color: var(--text-secondary); margin-top: 0.25rem;">Event: <?php echo htmlspecialchars($r['event_title']); ?></div>
                                                </td>
                                                <td>
                                                    <div style="font-size: 0.9rem;">Roll: <strong><?php echo htmlspecialchars($r['roll_no']); ?></strong></div>
                                                    <div style="font-size: 0.8rem; color: var(--text-secondary);">Batch: <?php echo htmlspecialchars($r['batch']); ?></div>
                                                </td>
                                                <td>
                                                    <div style="font-size: 0.9rem;">Role: <?php echo htmlspecialchars($r['event_role']); ?></div>
                                                    <div style="font-size: 0.8rem; color: var(--text-muted);">Food: <?php echo htmlspecialchars($r['food_preference']); ?></div>
                                                </td>
                                                <td>
                                                     <div class="receipt-thumbnail-container">
                                                         <?php if ($r['payment_screenshot'] === 'free'): ?>
                                                             <span class="badge badge-approved" style="background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); font-weight: 600; padding: 0.25rem 0.5rem; font-size: 0.75rem; border-radius: var(--radius-sm); display: inline-block;">Free Entry</span>
                                                         <?php else: ?>
                                                             <img src="<?php echo htmlspecialchars($r['payment_screenshot']); ?>" alt="Payment Proof" style="width: 50px; height: 35px; object-fit: cover; border-radius: var(--radius-sm); border: 1px solid var(--border-color); cursor: pointer;" onclick="openLightbox('<?php echo htmlspecialchars(addslashes($r['payment_screenshot'])); ?>')">
                                                         <?php endif; ?>
                                                     </div>
                                                </td>
                                                <td>
                                                    <?php if ($r['status'] === 'pending'): ?>
                                                        <span class="badge badge-pending">Pending</span>
                                                    <?php elseif ($r['status'] === 'approved'): ?>
                                                        <span class="badge badge-approved">Approved</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-rejected">Rejected</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($r['status'] === 'pending'): ?>
                                                        <div style="display: flex; gap: 0.5rem;">
                                                            <form action="admin_dashboard.php" method="POST" onsubmit="return confirm('Approve this registration and generate QR ticket?');">
                                                                <input type="hidden" name="reg_id" value="<?php echo $r['id']; ?>">
                                                                <button type="submit" name="action_approve" class="btn btn-success" style="padding: 0.4rem 0.8rem; font-size: 0.8rem;">Approve</button>
                                                            </form>
                                                            <form action="admin_dashboard.php" method="POST" onsubmit="return confirm('Reject this registration?');">
                                                                <input type="hidden" name="reg_id" value="<?php echo $r['id']; ?>">
                                                                <button type="submit" name="action_reject" class="btn btn-danger" style="padding: 0.4rem 0.8rem; font-size: 0.8rem;">Reject</button>
                                                            </form>
                                                        </div>
                                                    <?php elseif ($r['status'] === 'approved'): ?>
                                                        <div style="font-size: 0.8rem; color: var(--text-secondary);">
                                                            <div>Ticket Active</div>
                                                            <div style="margin-top: 0.4rem;">
                                                                <img src="<?php echo 'uploads/qrcodes/qr_' . $r['qr_token'] . '.png'; ?>" alt="QR Code" style="width: 40px; height: 40px; border-radius: 4px;">
                                                            </div>
                                                        </div>
                                                    <?php else: ?>
                                                        <span style="font-size: 0.85rem; color: var(--text-muted);">None</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Tab: Helper Manager -->
                <div id="tab-manage-helpers" class="tab-pane">
                    <div class="glass-panel" style="padding: 2rem;">
                        <h2>Manage Event Helpers</h2>
                        <p style="color: var(--text-secondary); margin-bottom: 2rem;">
                            Register helper accounts below. When volunteers scan student tickets with their phone's built-in Camera App, they will be prompted to enter their Passcode once to authorize the check-in.
                        </p>
                        
                        <div style="display: grid; grid-template-columns: 1fr; gap: 2rem; margin-bottom: 2rem;">
                            <!-- Add Helper Form -->
                            <div class="glass-panel" style="padding: 1.5rem; background: var(--bg-card); margin-bottom: 0;">
                                <h3 style="font-size: 1.2rem; margin-bottom: 1rem; color: var(--primary);">➕ Register New Helper</h3>
                                <form action="admin_dashboard.php" method="POST" style="display: flex; gap: 1rem; align-items: flex-end; flex-wrap: wrap;">
                                    <div class="form-group" style="flex-grow: 1; margin-bottom: 0; min-width: 250px;">
                                        <label class="form-label" for="helper-name-input">Helper / Volunteer Name</label>
                                        <input type="text" id="helper-name-input" name="helper_name" class="form-control" placeholder="E.g. Jane Doe" required>
                                    </div>
                                    <button type="submit" name="action_add_helper" class="btn btn-primary" style="height: 44px; padding: 0 1.5rem;">Register Helper</button>
                                </form>
                            </div>
                        </div>

                        <!-- Helpers List -->
                        <h3 style="font-size: 1.2rem; margin-bottom: 1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem;">Active Helpers</h3>
                        
                        <?php if (empty($helpers)): ?>
                            <div style="text-align: center; padding: 4rem 1rem; color: var(--text-muted);">
                                <span style="font-size: 3rem;">👥</span>
                                <h3 style="margin-top: 1rem; color: var(--text-secondary);">No helpers registered</h3>
                                <p style="font-size: 0.9rem; margin-top: 0.5rem;">Create a helper account above to authorize volunteers.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Helper Name</th>
                                            <th>Date Added</th>
                                            <th>Helper Passcode (For Verification)</th>
                                            <th style="text-align: right;">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($helpers as $h): ?>
                                            <tr>
                                                <td>
                                                    <strong style="color: var(--text-primary); font-size: 1.05rem;"><?php echo htmlspecialchars($h['name']); ?></strong>
                                                </td>
                                                <td>
                                                    <span style="font-size: 0.9rem; color: var(--text-secondary);"><?php echo date('M d, Y H:i', strtotime($h['created_at'])); ?></span>
                                                </td>
                                                <td>
                                                    <div style="display: flex; gap: 0.5rem; align-items: center; max-width: 480px;">
                                                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($h['helper_key']); ?>" readonly style="font-size: 0.8rem; font-family: monospace; background: var(--bg-main); padding: 0.5rem; height: 36px;" id="passcode-<?php echo $h['id']; ?>">
                                                        <button class="btn btn-secondary" onclick="copyTextValue('passcode-<?php echo $h['id']; ?>', 'btn-passcode-<?php echo $h['id']; ?>')" id="btn-passcode-<?php echo $h['id']; ?>" style="padding: 0 0.5rem; height: 36px; font-size: 0.8rem; flex-shrink: 0;">
                                                            Copy Passcode
                                                        </button>
                                                        <button class="btn btn-primary" onclick="openQRModal('<?php echo htmlspecialchars(addslashes($h['name'])); ?>', '<?php echo htmlspecialchars(addslashes($h['helper_key'])); ?>')" style="padding: 0 0.75rem; height: 36px; font-size: 0.8rem; flex-shrink: 0; background: linear-gradient(135deg, #FFA852, #FF6B35); border: none; color: #130E0A; font-weight: bold;">
                                                            Share Scanner
                                                        </button>
                                                    </div>
                                                </td>
                                                <td style="text-align: right;">
                                                    <form action="admin_dashboard.php" method="POST" onsubmit="return confirm('Are you sure you want to remove this helper? Their access passcode will be deactivated immediately.');" style="display: inline-block;">
                                                        <input type="hidden" name="helper_id" value="<?php echo $h['id']; ?>">
                                                        <button type="submit" name="action_delete_helper" class="btn btn-danger" style="padding: 0.4rem 0.8rem; font-size: 0.8rem;">Revoke Access</button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- ====================================================
                     QR CODE SHARE MODAL (for Helper Manager)
                ===================================================== -->
                <div id="helper-qr-modal" class="modal" onclick="closeQRModal()">
                    <div class="modal-content" style="max-width: 440px; padding: 2.5rem; border-radius: 20px;" onclick="event.stopPropagation()">
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 1.5rem;">
                            <div>
                                <h3 style="margin:0; font-size: 1.2rem; display:flex; align-items:center; gap:0.5rem;">📱 Share Scanner</h3>
                                <p id="qr-modal-subtitle" style="color: var(--text-secondary); font-size: 0.85rem; margin-top: 0.25rem;">Scan to open on phone</p>
                            </div>
                            <button onclick="closeQRModal()" style="background: none; border: none; font-size: 1.5rem; cursor: pointer; color: var(--text-muted); padding: 0.25rem;">✕</button>
                        </div>

                        <!-- QR Code Image (generated via qrserver.com API) -->
                        <div style="display: flex; justify-content: center; margin-bottom: 1.5rem;">
                            <div style="padding: 1rem; background: white; border-radius: 12px; box-shadow: 0 4px 20px rgba(0,0,0,0.15); display: inline-block;">
                                <img id="qr-code-img" src="" alt="QR Code" style="width: 200px; height: 200px; display: block;">
                            </div>
                        </div>

                        <!-- URL display + copy -->
                        <div style="display: flex; gap: 0.5rem; margin-bottom: 1.25rem;">
                            <input type="text" id="qr-modal-url" class="form-control" readonly style="font-family: monospace; font-size: 0.78rem; height: 38px;">
                            <button class="btn btn-secondary" id="qr-copy-url-btn" onclick="copyQRUrl()" style="height: 38px; padding: 0 0.75rem; font-size: 0.8rem; white-space: nowrap;">Copy URL</button>
                        </div>

                        <!-- Instructions -->
                        <div style="background: var(--primary-glow); border: 1px solid var(--primary); border-radius: 10px; padding: 0.85rem 1rem; font-size: 0.82rem; color: var(--text-secondary); line-height: 1.5;">
                            <strong style="color: var(--primary); display: block; margin-bottom: 0.3rem;">📋 How to use</strong>
                            Ask the helper to scan this QR code with their phone camera, or share the URL via WhatsApp/SMS. The scanner opens directly — no passcode entry needed.
                        </div>

                        <!-- Add to Home Screen tip -->
                        <div style="margin-top: 1rem; font-size: 0.78rem; color: var(--text-muted); text-align: center;">
                            💡 Helpers can tap <em>"Add to Home Screen"</em> in their browser for quick app-like access.
                        </div>
                    </div>
                </div>

                <!-- Tab: Manage Events -->
                <div id="tab-manage-events" class="tab-pane">
                    <div class="glass-panel" style="padding: 2rem;">
                        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.5rem;">
                            <div>
                                <h2>Manage Events & Control Registrations</h2>
                                <p style="color: var(--text-secondary); margin-top: 0.25rem;">Toggle visibility (Activate/Deactivate) or registration status (Open/Close) for events.</p>
                            </div>
                            <button class="btn btn-primary" onclick="openAddEventModal()">
                                ➕ Add New Event
                            </button>
                        </div>
                        
                        <div style="display: flex; gap: 1rem; align-items: center; margin-bottom: 1.5rem; justify-content: space-between; flex-wrap: wrap;">
                            <div class="search-box-wrapper" style="max-width: 380px;">
                                <span class="search-icon">🔍</span>
                                <input type="text" id="event-search" class="search-input" placeholder="Search events by title, description or location..." onkeyup="filterEvents()">
                            </div>
                        </div>
                        
                        <?php if (empty($admin_events)): ?>
                            <div style="text-align: center; padding: 4rem 1rem; color: var(--text-muted);">
                                <span style="font-size: 3rem;">📅</span>
                                <h3 style="margin-top: 1rem; color: var(--text-secondary);">No events found</h3>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Event Name</th>
                                            <th>Date & Location</th>
                                            <th>Price</th>
                                            <th>Registrations</th>
                                            <th>Visibility Status</th>
                                            <th>Registration Status</th>
                                            <th>Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($admin_events as $ev): ?>
                                            <tr>
                                                <td>
                                                    <strong style="color: var(--text-primary);"><?php echo htmlspecialchars($ev['title']); ?></strong>
                                                    <div style="font-size: 0.85rem; color: var(--text-muted); margin-top: 0.25rem;">
                                                        <?php echo htmlspecialchars(substr($ev['description'], 0, 75)) . (strlen($ev['description']) > 75 ? '...' : ''); ?>
                                                    </div>
                                                </td>
                                                <td>
                                                    <div style="font-size: 0.9rem; font-weight: 500;"><?php echo date('M d, Y H:i', strtotime($ev['event_date'])); ?></div>
                                                    <div style="font-size: 0.8rem; color: var(--text-secondary);"><?php echo htmlspecialchars($ev['location']); ?></div>
                                                </td>
                                                <td>
                                                    <strong style="color: var(--primary);">$<?php echo number_format($ev['price'], 2); ?></strong>
                                                </td>
                                                <td>
                                                    <span style="font-size: 0.95rem; font-weight: 700; color: var(--text-primary); background: var(--bg-input); padding: 0.25rem 0.6rem; border-radius: var(--radius-sm); border: 1px solid var(--border-color); display: inline-block; min-width: 30px; text-align: center;">
                                                        <?php echo intval($ev['total_regs']); ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <?php if ($ev['is_active'] == 1): ?>
                                                        <span class="badge badge-approved">Active</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-deactivated">Inactive</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php if ($ev['reg_status'] === 'open'): ?>
                                                        <span class="badge badge-active">Open</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-pending">Closed</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div style="display: flex; gap: 0.5rem; flex-wrap: wrap;">
                                                        <!-- Toggle Visibility Form -->
                                                        <form action="admin_dashboard.php" method="POST">
                                                            <input type="hidden" name="event_id" value="<?php echo $ev['id']; ?>">
                                                            <input type="hidden" name="current_status" value="<?php echo $ev['is_active']; ?>">
                                                            <?php if ($ev['is_active'] == 1): ?>
                                                                <button type="submit" name="action_toggle_active" class="btn btn-secondary" style="padding: 0.4rem 0.8rem; font-size: 0.8rem;">
                                                                    Deactivate
                                                                </button>
                                                            <?php else: ?>
                                                                <button type="submit" name="action_toggle_active" class="btn btn-primary" style="padding: 0.4rem 0.8rem; font-size: 0.8rem;">
                                                                    Activate
                                                                </button>
                                                            <?php endif; ?>
                                                        </form>
                                                        
                                                        <!-- Toggle Registration Form -->
                                                        <form action="admin_dashboard.php" method="POST">
                                                            <input type="hidden" name="event_id" value="<?php echo $ev['id']; ?>">
                                                            <input type="hidden" name="current_status" value="<?php echo $ev['reg_status']; ?>">
                                                            <?php if ($ev['reg_status'] === 'open'): ?>
                                                                <button type="submit" name="action_toggle_reg" class="btn btn-danger" style="padding: 0.4rem 0.8rem; font-size: 0.8rem;">
                                                                    Close Reg
                                                                </button>
                                                            <?php else: ?>
                                                                <button type="submit" name="action_toggle_reg" class="btn btn-success" style="padding: 0.4rem 0.8rem; font-size: 0.8rem;">
                                                                    Open Reg
                                                                </button>
                                                            <?php endif; ?>
                                                        </form>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Tab: Manage Activities -->
                <div id="tab-manage-activities" class="tab-pane">
                    <div style="display: grid; grid-template-columns: 1fr; gap: 2rem;">
                        
                        <!-- Panel 1: Create a new activity -->
                        <div class="glass-panel" style="padding: 2rem;">
                            <h2>Define Event Activity / Performance</h2>
                            <p style="color: var(--text-secondary); margin-top: 0.25rem;">Create a performance category (Solo Singing, Duet, Group Dance, etc.) for a specific event.</p>
                            
                            <form action="admin_dashboard.php" method="POST" style="margin-top: 1.5rem;">
                                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 1rem;">
                                    <div class="form-group">
                                        <label class="form-label" for="act-event-select">Select Event</label>
                                        <select id="act-event-select" name="event_id" class="form-control" required>
                                            <option value="" disabled selected>Select event...</option>
                                            <?php foreach ($admin_events as $ev): ?>
                                                <option value="<?php echo $ev['id']; ?>"><?php echo htmlspecialchars($ev['title']); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label" for="act-title">Activity Title</label>
                                        <input type="text" id="act-title" name="title" class="form-control" placeholder="E.g. Solo Singing, Group Dance" required>
                                    </div>
                                    
                                    <div class="form-group">
                                        <label class="form-label" for="act-type">Performance Type</label>
                                        <select id="act-type" name="activity_type" class="form-control" required>
                                            <option value="solo" selected>Solo Performance</option>
                                            <option value="duet">Duet Performance</option>
                                            <option value="group">Group Performance</option>
                                        </select>
                                    </div>
                                </div>
                                
                                <div class="form-group" style="margin-top: 1rem;">
                                    <label class="form-label" for="act-desc">Description (Optional)</label>
                                    <textarea id="act-desc" name="description" class="form-control" rows="2" placeholder="Rules, time limits, or generic info..."></textarea>
                                </div>
                                
                                <button type="submit" name="action_add_activity" class="btn btn-primary" style="margin-top: 1rem; width: auto; font-weight: 700;">
                                    Create Activity
                                </button>
                            </form>
                        </div>
                        
                        <!-- Panel 2: Existing Activities list -->
                        <div class="glass-panel" style="padding: 2rem;">
                            <h2>Existing Activities</h2>
                            
                            <?php if (empty($all_activities)): ?>
                                <p style="color: var(--text-muted); text-align: center; padding: 2rem;">No activities have been defined yet.</p>
                            <?php else: ?>
                                <div class="table-responsive">
                                    <table class="data-table">
                                        <thead>
                                            <tr>
                                                <th>Event Name</th>
                                                <th>Activity Title</th>
                                                <th>Type</th>
                                                <th>Description</th>
                                                <th style="text-align: right;">Action</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($all_activities as $act): ?>
                                                <tr>
                                                    <td><strong><?php echo htmlspecialchars($act['event_title']); ?></strong></td>
                                                    <td><?php echo htmlspecialchars($act['title']); ?></td>
                                                    <td><span class="badge badge-approved" style="font-size: 0.75rem; font-family: monospace;"><?php echo $act['activity_type']; ?></span></td>
                                                    <td><?php echo htmlspecialchars($act['description'] ?: '-'); ?></td>
                                                    <td style="text-align: right;">
                                                        <form action="admin_dashboard.php" method="POST" style="margin: 0;" onsubmit="return confirm('Are you sure you want to delete this activity? This will remove all registrations associated with it.');">
                                                            <input type="hidden" name="activity_id" value="<?php echo $act['id']; ?>">
                                                            <button type="submit" name="action_delete_activity" class="btn btn-danger" style="padding: 0.35rem 0.75rem; font-size: 0.8rem;">Delete</button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <!-- Panel 3: Activity Registrations Manager -->
                        <div class="glass-panel" style="padding: 2rem;">
                            <h2>Activity Registrations Manager</h2>
                            <p style="color: var(--text-secondary); margin-top: 0.25rem;">View, approve, or reject student and team activity registration requests.</p>
                            
                            <?php if (empty($grouped_act_regs)): ?>
                                <p style="color: var(--text-muted); text-align: center; padding: 3rem 1rem;">No activity registration requests submitted yet.</p>
                            <?php else: ?>
                                <?php foreach ($grouped_act_regs as $act_id => $group): ?>
                                    <div style="margin-top: 2rem; border: 1px solid var(--border-color); border-radius: var(--radius-md); overflow: hidden; background: rgba(0,0,0,0.1);">
                                        <div style="background: var(--bg-input); padding: 1rem; border-bottom: 1px solid var(--border-color); display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 0.5rem;">
                                            <div>
                                                <h4 style="margin: 0; font-size: 1.1rem; color: var(--text-primary);"><?php echo htmlspecialchars($group['activity_title']); ?></h4>
                                                <span style="font-size: 0.8rem; color: var(--text-secondary);">Event: <strong><?php echo htmlspecialchars($group['event_title']); ?></strong></span>
                                            </div>
                                            <span class="badge badge-approved" style="font-size: 0.65rem; text-transform: uppercase; display: inline-block; margin-top: 0.25rem; font-weight: bold; background: rgba(59, 130, 246, 0.15); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.3);">
                                                ⚡ <?php echo $group['activity_type']; ?>
                                            </span>
                                        </div>
                                        
                                        <div style="padding: 1rem; display: flex; flex-direction: column; gap: 1rem;">
                                            <?php foreach ($group['teams'] as $team_key => $team): ?>
                                                <div style="background: rgba(255,255,255,0.01); border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 1rem; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 1rem;">
                                                    <div>
                                                        <?php if (!empty($team['team_name'])): ?>
                                                            <div style="font-size: 1.05rem; font-weight: bold; color: var(--primary);">Team: <?php echo htmlspecialchars($team['team_name']); ?></div>
                                                        <?php endif; ?>
                                                        
                                                        <div style="margin-top: 0.5rem;">
                                                            <div style="font-size: 0.8rem; font-weight: bold; color: var(--text-secondary); margin-bottom: 0.25rem;">Performers:</div>
                                                            <ul style="list-style: none; padding-left: 0; margin: 0; display: flex; flex-direction: column; gap: 0.2rem;">
                                                                <?php foreach ($team['members'] as $m): 
                                                                    $is_leader = ($m['registration_id'] === $team['team_leader_reg_id']);
                                                                ?>
                                                                    <li style="font-size: 0.85rem; color: var(--text-primary); display: flex; align-items: center; gap: 0.4rem;">
                                                                        👤 <strong><?php echo htmlspecialchars($m['student_name']); ?></strong> (Roll: <?php echo htmlspecialchars($m['roll_no']); ?>, <?php echo htmlspecialchars($m['stream']); ?> - <?php echo htmlspecialchars($m['batch']); ?>)
                                                                        <?php if ($is_leader): ?>
                                                                            <span class="badge badge-approved" style="font-size: 0.65rem; padding: 0.1rem 0.3rem;">Leader</span>
                                                                        <?php endif; ?>
                                                                    </li>
                                                                <?php endforeach; ?>
                                                            </ul>
                                                        </div>
                                                        
                                                        <?php if (!empty($team['track_link'])): ?>
                                                            <div style="font-size: 0.8rem; color: var(--text-secondary); margin-top: 0.5rem; word-break: break-all;">
                                                                🎵 <strong>Music Track:</strong> <a href="<?php echo htmlspecialchars($team['track_link']); ?>" target="_blank" style="color: var(--primary); text-decoration: underline;"><?php echo htmlspecialchars($team['track_link']); ?></a>
                                                            </div>
                                                        <?php endif; ?>
                                                    </div>
                                                    
                                                    <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 0.5rem;">
                                                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                                                            <span style="font-size: 0.8rem; color: var(--text-muted); font-weight: bold; text-transform: uppercase;">Status:</span>
                                                            <?php if ($team['status'] === 'pending'): ?>
                                                                <span class="badge badge-pending">Pending</span>
                                                            <?php elseif ($team['status'] === 'approved'): ?>
                                                                <span class="badge badge-approved">Approved</span>
                                                            <?php else: ?>
                                                                <span class="badge badge-rejected">Rejected</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        
                                                        <div style="display: flex; gap: 0.25rem; margin-top: 0.5rem;">
                                                            <!-- Approve form -->
                                                            <form action="admin_dashboard.php" method="POST" style="margin: 0;">
                                                                <input type="hidden" name="activity_id" value="<?php echo $act_id; ?>">
                                                                <input type="hidden" name="team_name" value="<?php echo htmlspecialchars($team['team_name'] ?? ''); ?>">
                                                                <input type="hidden" name="team_leader_reg_id" value="<?php echo $team['team_leader_reg_id']; ?>">
                                                                <input type="hidden" name="registration_id" value="<?php echo $team['members'][0]['registration_id']; ?>">
                                                                <button type="submit" name="action_approve_activity_reg" class="btn btn-success" style="padding: 0.35rem 0.65rem; font-size: 0.75rem;">Approve</button>
                                                            </form>
                                                            
                                                            <!-- Reject form -->
                                                            <form action="admin_dashboard.php" method="POST" style="margin: 0;">
                                                                <input type="hidden" name="activity_id" value="<?php echo $act_id; ?>">
                                                                <input type="hidden" name="team_name" value="<?php echo htmlspecialchars($team['team_name'] ?? ''); ?>">
                                                                <input type="hidden" name="team_leader_reg_id" value="<?php echo $team['team_leader_reg_id']; ?>">
                                                                <input type="hidden" name="registration_id" value="<?php echo $team['members'][0]['registration_id']; ?>">
                                                                <button type="submit" name="action_reject_activity_reg" class="btn btn-danger" style="padding: 0.35rem 0.65rem; font-size: 0.75rem;">Reject</button>
                                                            </form>
                                                            
                                                            <!-- Delete form -->
                                                            <form action="admin_dashboard.php" method="POST" style="margin: 0;" onsubmit="return confirm('Are you sure you want to delete this activity registration entry?');">
                                                                <input type="hidden" name="activity_id" value="<?php echo $act_id; ?>">
                                                                <input type="hidden" name="team_name" value="<?php echo htmlspecialchars($team['team_name'] ?? ''); ?>">
                                                                <input type="hidden" name="team_leader_reg_id" value="<?php echo $team['team_leader_reg_id']; ?>">
                                                                <input type="hidden" name="registration_id" value="<?php echo $team['members'][0]['registration_id']; ?>">
                                                                <button type="submit" name="action_delete_activity_reg" class="btn btn-secondary" style="padding: 0.35rem 0.65rem; font-size: 0.75rem; border-color: var(--border-color); color: var(--text-primary);">Delete</button>
                                                            </form>
                                                        </div>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                        
                    </div>
                </div>

                <!-- Tab: System Logs -->
                <div id="tab-system-logs" class="tab-pane">
                    <div class="glass-panel" style="padding: 2rem;">
                        <h2>System Audit Logs</h2>
                        <p style="color: var(--text-secondary); margin-bottom: 2rem;">Chronological record of administrative operations, helper scanner activities, and database updates.</p>
                        
                        <div style="display: flex; gap: 1rem; align-items: center; margin-bottom: 1.5rem; justify-content: space-between; flex-wrap: wrap;">
                            <div class="search-box-wrapper" style="max-width: 380px;">
                                <span class="search-icon">🔍</span>
                                <input type="text" id="log-search" class="search-input" placeholder="Search logs by message or type..." onkeyup="filterSystemLogs()">
                            </div>
                            <form action="admin_dashboard.php" method="POST" onsubmit="return confirm('Are you sure you want to permanently clear all system logs?');">
                                <button type="submit" name="action_clear_logs" class="btn btn-danger" style="display: flex; align-items: center; gap: 0.5rem; padding: 0.5rem 1.25rem;">
                                    <span>🗑️</span> Clear System Logs
                                </button>
                            </form>
                        </div>
                        
                        <?php
                        // Fetch system logs
                        try {
                            $logs_stmt = $conn->query("SELECT * FROM system_logs ORDER BY created_at DESC LIMIT 500");
                            $system_logs = $logs_stmt->fetchAll(PDO::FETCH_ASSOC);
                        } catch (PDOException $e) {
                            $system_logs = [];
                        }
                        ?>
                        
                        <?php if (empty($system_logs)): ?>
                            <div style="text-align: center; padding: 4rem 1rem; color: var(--text-muted);">
                                <span style="font-size: 3rem;">📜</span>
                                <h3 style="margin-top: 1rem; color: var(--text-secondary);">No log entries found</h3>
                                <p style="font-size: 0.9rem; margin-top: 0.5rem;">System events will be logged here automatically.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive" style="max-height: 550px; overflow-y: auto; border: 1px solid var(--border-color); border-radius: var(--radius-md);">
                                <table class="data-table" id="system-logs-table">
                                    <thead>
                                        <tr>
                                            <th style="width: 180px;">Timestamp</th>
                                            <th style="width: 120px;">Log Level</th>
                                            <th>Log Message</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($system_logs as $log): ?>
                                            <?php
                                            $badge_class = 'badge-pending';
                                            $icon = 'ℹ️';
                                            if ($log['log_type'] === 'success') {
                                                $badge_class = 'badge-approved';
                                                $icon = '✅';
                                            } elseif ($log['log_type'] === 'error') {
                                                $badge_class = 'badge-rejected';
                                                $icon = '⚠️';
                                            }
                                            ?>
                                            <tr>
                                                <td style="white-space: nowrap; font-size: 0.85rem; color: var(--text-secondary); font-family: monospace;">
                                                    <?php echo date('Y-m-d H:i:s', strtotime($log['created_at'])); ?>
                                                </td>
                                                <td>
                                                    <span class="badge <?php echo $badge_class; ?>" style="text-transform: uppercase; font-size: 0.75rem; font-weight: 600; display: inline-flex; align-items: center; gap: 0.25rem;">
                                                        <span><?php echo $icon; ?></span><?php echo htmlspecialchars($log['log_type']); ?>
                                                    </span>
                                                </td>
                                                <td style="font-size: 0.9rem; color: var(--text-primary); line-height: 1.4;">
                                                    <?php echo htmlspecialchars($log['message']); ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div> <!-- End of tab-system-logs -->

                <!-- Tab: Feedback Management -->
                <div id="tab-feedback-mgmt" class="tab-pane">
                    <div class="glass-panel" style="padding: 2rem;">
                        <h2>Event Feedback & Ratings</h2>
                        <p style="color: var(--text-secondary); margin-bottom: 2rem;">Review event satisfaction metrics, student ratings, and suggestions.</p>

                        <!-- Average Event Ratings Section -->
                        <h3 style="font-size: 1.15rem; margin-bottom: 1rem; color: var(--primary);">⭐ Average Ratings by Event</h3>
                        <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); margin-bottom: 2.5rem; gap: 1.5rem;">
                            <?php if (empty($avg_ratings)): ?>
                                <div class="stat-card" style="padding: 1rem; text-align: center; color: var(--text-muted);">No rating statistics available.</div>
                            <?php else: ?>
                                <?php foreach ($avg_ratings as $ar): ?>
                                    <div class="stat-card" style="border-left: 4px solid var(--primary); padding: 1.25rem;">
                                        <span class="stat-title" style="font-size: 0.85rem; font-weight: 700; color: var(--text-primary); text-transform: none; display: block; margin-bottom: 0.5rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width: 250px;">
                                            <?php echo htmlspecialchars($ar['title']); ?>
                                        </span>
                                        <div style="display: flex; align-items: center; gap: 0.5rem;">
                                            <span class="stat-value" style="font-size: 1.75rem; margin: 0;">
                                                <?php echo $ar['avg_rating'] !== null ? number_format($ar['avg_rating'], 1) : '0.0'; ?>
                                            </span>
                                            <div style="display: flex; flex-direction: column; gap: 0.15rem;">
                                                <div><?php echo renderFeedbackStars(round($ar['avg_rating'] ?: 0)); ?></div>
                                                <span style="font-size: 0.75rem; color: var(--text-muted);"><?php echo intval($ar['total_feedbacks']); ?> feedback(s)</span>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <!-- Feedback Log List -->
                        <h3 style="font-size: 1.15rem; margin-bottom: 1rem; color: var(--primary); border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem;">Feedback Log</h3>
                        
                        <div style="display: flex; gap: 1rem; align-items: center; margin-bottom: 1.5rem; justify-content: space-between; flex-wrap: wrap;">
                            <div class="search-box-wrapper" style="max-width: 380px; flex-grow: 1;">
                                <span class="search-icon">🔍</span>
                                <input type="text" id="feedback-search" class="search-input" placeholder="Search student or event..." onkeyup="filterFeedbacks()">
                            </div>
                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                <label for="rating-filter" style="font-size: 0.85rem; font-weight: 600; color: var(--text-secondary); text-transform: uppercase;">Rating:</label>
                                <select id="rating-filter" class="form-control" style="width: 140px; padding: 0.4rem 0.8rem; height: 38px; font-size: 0.85rem;" onchange="filterFeedbacks()">
                                    <option value="all">All Ratings</option>
                                    <option value="5">⭐⭐⭐⭐⭐ (5)</option>
                                    <option value="4">⭐⭐⭐⭐ (4)</option>
                                    <option value="3">⭐⭐⭐ (3)</option>
                                    <option value="2">⭐⭐ (2)</option>
                                    <option value="1">⭐ (1)</option>
                                </select>
                            </div>
                        </div>

                        <?php if (empty($feedbacks)): ?>
                            <div style="text-align: center; padding: 4rem 1rem; color: var(--text-muted);">
                                <span style="font-size: 3rem;">💬</span>
                                <h3 style="margin-top: 1rem; color: var(--text-secondary);">No feedback submitted yet</h3>
                                <p style="font-size: 0.9rem; margin-top: 0.5rem;">Feedback will appear here once attendees check out and submit their forms.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive" style="max-height: 550px; overflow-y: auto;">
                                <table class="data-table" id="feedback-table">
                                    <thead>
                                        <tr>
                                            <th>Student Details</th>
                                            <th>Event Title</th>
                                            <th style="width: 120px;">Rating</th>
                                            <th>Feedback / Comment</th>
                                            <th style="width: 150px;">Submitted At</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($feedbacks as $f): ?>
                                            <tr data-rating="<?php echo $f['rating']; ?>">
                                                <td>
                                                    <strong class="feedback-student-name" style="color: var(--text-primary);"><?php echo htmlspecialchars($f['student_name']); ?></strong>
                                                    <div style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.15rem;"><?php echo htmlspecialchars($f['email']); ?></div>
                                                </td>
                                                <td class="feedback-event-title" style="font-weight: 500; font-size: 0.95rem;">
                                                    <?php echo htmlspecialchars($f['event_title']); ?>
                                                </td>
                                                <td>
                                                    <div style="display: none;"><?php echo $f['rating']; ?></div> <!-- for search helper -->
                                                    <?php echo renderFeedbackStars($f['rating']); ?>
                                                </td>
                                                <td style="font-size: 0.9rem; color: var(--text-secondary); line-height: 1.4; white-space: pre-line;">
                                                    <?php echo !empty($f['comment']) ? htmlspecialchars($f['comment']) : '<span style="color: var(--text-muted); font-style: italic;">No comment provided</span>'; ?>
                                                </td>
                                                <td style="font-size: 0.85rem; color: var(--text-muted); font-family: monospace;">
                                                    <?php echo date('M d, Y H:i', strtotime($f['created_at'])); ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div> <!-- End of tab-feedback-mgmt -->

                <!-- Tab: Certificate Generator -->
                <div id="tab-cert-generator" class="tab-pane">
                    <div class="glass-panel" style="padding: 2rem;">
                        <h2>Certificate Generator & Templates</h2>
                        <p style="color: var(--text-secondary); margin-bottom: 2rem;">Upload Canva templates (blank backgrounds) for each event, generate certificates for approved students in bulk, and download/manage them event-wise.</p>
                        
                        <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1.5rem; margin-bottom: 2.5rem;">
                            <?php foreach ($admin_events as $ev): 
                                // Fetch count of approved registrations for this event
                                $stmt_app_count = $conn->prepare("SELECT COUNT(*) FROM registrations WHERE event_id = ? AND status = 'approved'");
                                $stmt_app_count->execute([$ev['id']]);
                                $app_count = $stmt_app_count->fetchColumn();

                                // Fetch count of generated certificates
                                $stmt_cert_count = $conn->prepare("SELECT COUNT(*) FROM certificates WHERE event_id = ?");
                                $stmt_cert_count->execute([$ev['id']]);
                                $cert_count = $stmt_cert_count->fetchColumn();
                            ?>
                                <div class="stat-card" style="display: flex; flex-direction: column; justify-content: space-between; min-height: 250px; border-left: 4px solid <?php echo !empty($ev['certificate_template']) ? 'var(--success)' : 'var(--warning)'; ?>; padding: 1.5rem; background: rgba(255, 255, 255, 0.02); border-radius: var(--radius-md);">
                                    <div>
                                        <h3 style="font-size: 1.1rem; color: var(--text-primary); margin-bottom: 0.5rem;"><?php echo htmlspecialchars($ev['title']); ?></h3>
                                        <div style="font-size: 0.85rem; color: var(--text-muted); margin-bottom: 1rem;">
                                            📅 <?php echo date('M d, Y', strtotime($ev['event_date'])); ?> | 📍 <?php echo htmlspecialchars($ev['location']); ?>
                                        </div>
                                        
                                        <div style="display: flex; flex-direction: column; gap: 0.5rem; margin-bottom: 1.25rem; font-size: 0.9rem;">
                                            <div style="display: flex; justify-content: space-between;">
                                                <span style="color: var(--text-secondary);">Approved Students:</span>
                                                <strong style="color: var(--text-primary);"><?php echo $app_count; ?></strong>
                                            </div>
                                            <div style="display: flex; justify-content: space-between;">
                                                <span style="color: var(--text-secondary);">Certificates Generated:</span>
                                                <strong style="color: <?php echo $cert_count > 0 ? 'var(--success)' : 'var(--text-muted)'; ?>;"><?php echo $cert_count; ?> / <?php echo $app_count; ?></strong>
                                            </div>
                                            <div style="display: flex; justify-content: space-between; align-items: center;">
                                                <span style="color: var(--text-secondary);">Template Background:</span>
                                                <?php if (!empty($ev['certificate_template'])): ?>
                                                    <span class="badge badge-approved" style="font-size: 0.75rem; padding: 0.15rem 0.5rem; cursor: pointer;" onclick="openLightbox('<?php echo htmlspecialchars(addslashes($ev['certificate_template'])); ?>')">Uploaded (View)</span>
                                                <?php else: ?>
                                                    <span class="badge badge-pending" style="font-size: 0.75rem; padding: 0.15rem 0.5rem;">Missing</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                    
                                    <div style="border-top: 1px solid var(--border-color); padding-top: 1rem; margin-top: auto;">
                                        <!-- Canva Link -->
                                        <div style="margin-bottom: 0.75rem;">
                                            <a href="https://www.canva.com/templates/?query=certificate" target="_blank" class="btn" style="display: flex; align-items: center; justify-content: center; gap: 0.4rem; font-size: 0.75rem; color: #ffffff; background: linear-gradient(135deg, #7d2ae8, #00c4cc); padding: 0.4rem; border-radius: 6px; text-decoration: none; font-weight: 600; box-shadow: 0 2px 4px rgba(125,42,232,0.15); transition: all 0.2s;" onmouseover="this.style.opacity='0.9';" onmouseout="this.style.opacity='1';">
                                                🎨 Choose Template from Canva
                                            </a>
                                        </div>

                                        <!-- Template Upload Form -->
                                        <form action="admin_dashboard.php" method="POST" enctype="multipart/form-data" style="margin-bottom: 0.75rem;">
                                            <input type="hidden" name="event_id" value="<?php echo $ev['id']; ?>">
                                            <div style="display: flex; gap: 0.5rem; align-items: center;">
                                                <input type="file" name="template_image" accept="image/png, image/jpeg, image/jpg" required style="font-size: 0.75rem; color: var(--text-secondary); max-width: 170px;">
                                                <button type="submit" name="action_upload_template" class="btn btn-primary" style="padding: 0.35rem 0.75rem; font-size: 0.75rem; white-space: nowrap;">
                                                    Upload
                                                </button>
                                            </div>
                                        </form>

                                        <!-- Generate Trigger Buttons -->
                                        <div style="display: flex; gap: 0.5rem;">
                                            <?php if (!empty($ev['certificate_template']) && $app_count > 0): ?>
                                                <form action="admin_dashboard.php" method="POST" style="flex: 1;" onsubmit="return confirm('Generate/overwrite certificates for all approved registrations of this event?');">
                                                    <input type="hidden" name="event_id" value="<?php echo $ev['id']; ?>">
                                                    <button type="submit" name="action_generate_certs" class="btn btn-success" style="width: 100%; padding: 0.4rem; font-size: 0.8rem; text-align: center; display: block;">
                                                        ⚡ Generate Certificates
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <button class="btn btn-secondary" disabled style="flex: 1; padding: 0.4rem; font-size: 0.8rem; opacity: 0.5; cursor: not-allowed; text-align: center;">
                                                    ⚡ Generate Certificates
                                                </button>
                                            <?php endif; ?>
                                            
                                            <?php if ($cert_count > 0): ?>
                                                <form action="admin_dashboard.php" method="POST" onsubmit="return confirm('Delete all generated certificates for this event from the database and files?');">
                                                    <input type="hidden" name="event_id" value="<?php echo $ev['id']; ?>">
                                                    <button type="submit" name="action_delete_all_certs" class="btn btn-danger" style="padding: 0.4rem 0.6rem; font-size: 0.8rem; display: flex; align-items: center; justify-content: center;">
                                                        🗑️
                                                    </button>
                                                </form>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Card view for event wise certificates list -->
                        <h3 style="font-size: 1.25rem; margin-top: 3rem; margin-bottom: 1.5rem; color: var(--primary);">📋 View Certificates by Event</h3>
                        <div style="display: flex; flex-direction: column; gap: 2rem;">
                            <?php foreach ($admin_events as $ev):
                                // Fetch certificates generated for this event
                                $stmt_certs_list = $conn->prepare("
                                    SELECT c.id as cert_id, c.pdf_path, c.qr_path, c.created_at, u.name as student_name, r.roll_no, r.qr_token 
                                    FROM certificates c 
                                    JOIN users u ON c.user_id = u.id 
                                    JOIN registrations r ON c.user_id = r.user_id AND c.event_id = r.event_id
                                    WHERE c.event_id = ?
                                    ORDER BY u.name ASC
                                ");
                                $stmt_certs_list->execute([$ev['id']]);
                                $certs_list = $stmt_certs_list->fetchAll(PDO::FETCH_ASSOC);
                                
                                foreach ($certs_list as &$cl) {
                                    $cl['roll_no'] = decryptData($cl['roll_no']);
                                }
                                unset($cl);
                                
                                if (empty($certs_list)) continue;
                            ?>
                                <div class="glass-panel" style="padding: 1.5rem; background: rgba(255, 255, 255, 0.01); border: 1px solid var(--border-color); border-radius: var(--radius-md);">
                                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1.25rem; flex-wrap: wrap; gap: 1rem;">
                                        <h4 style="font-size: 1.15rem; color: var(--text-primary); margin: 0;">🏆 <?php echo htmlspecialchars($ev['title']); ?></h4>
                                        <span style="font-size: 0.85rem; color: var(--text-muted); background: var(--bg-input); padding: 0.25rem 0.75rem; border-radius: 20px; border: 1px solid var(--border-color);">
                                            Total: <strong><?php echo count($certs_list); ?></strong> generated
                                        </span>
                                    </div>
                                    
                                    <div class="table-responsive">
                                        <table class="data-table">
                                            <thead>
                                                <tr>
                                                    <th>Student Details</th>
                                                    <th>Unique ID</th>
                                                    <th>QR Code</th>
                                                    <th>Generated At</th>
                                                    <th style="text-align: right;">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($certs_list as $cl): ?>
                                                    <tr>
                                                        <td>
                                                            <strong style="color: var(--text-primary);"><?php echo htmlspecialchars($cl['student_name']); ?></strong>
                                                            <div style="font-size: 0.8rem; color: var(--text-secondary); margin-top: 0.2rem;">Roll: <?php echo htmlspecialchars($cl['roll_no']); ?></div>
                                                        </td>
                                                        <td style="font-family: monospace; font-size: 0.9rem; color: var(--primary);">
                                                            EVT/<?php echo $ev['id']; ?>/<?php echo $cl['cert_id']; ?>
                                                        </td>
                                                        <td>
                                                            <!-- Display the small QR code inline -->
                                                            <div style="display: flex; align-items: center; gap: 0.5rem;">
                                                                <img src="<?php echo htmlspecialchars($cl['qr_path']); ?>" alt="QR" style="width: 32px; height: 32px; background: white; border-radius: var(--radius-sm); border: 1px solid var(--border-color); cursor: pointer;" onclick="openLightbox('<?php echo htmlspecialchars(addslashes($cl['qr_path'])); ?>')">
                                                            </div>
                                                        </td>
                                                        <td style="font-size: 0.85rem; color: var(--text-secondary);">
                                                            <?php echo date('M d, Y H:i', strtotime($cl['created_at'])); ?>
                                                        </td>
                                                        <td style="text-align: right;">
                                                            <div style="display: inline-flex; gap: 0.5rem; align-items: center;">
                                                                <a href="<?php echo htmlspecialchars($cl['pdf_path']); ?>" target="_blank" class="btn btn-primary" style="padding: 0.4rem 0.8rem; font-size: 0.75rem; display: inline-flex; align-items: center; gap: 0.25rem; text-decoration: none;">
                                                                    📥 Download PDF
                                                                </a>
                                                                <form action="admin_dashboard.php" method="POST" style="margin: 0;" onsubmit="return confirm('Delete this certificate?');">
                                                                    <input type="hidden" name="cert_id" value="<?php echo $cl['cert_id']; ?>">
                                                                    <button type="submit" name="action_delete_certificate" class="btn btn-danger" style="padding: 0.4rem 0.6rem; font-size: 0.75rem;">
                                                                        🗑️
                                                                    </button>
                                                                </form>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div> <!-- End of tab-cert-generator -->

                <!-- Tab: Export Reports -->
                <div id="tab-export-reports" class="tab-pane">
                    <div class="glass-panel" style="padding: 2rem;">
                        <h2>Exported Reports Archive</h2>
                        <p style="color: var(--text-secondary); margin-bottom: 1.5rem;">
                            Every time an event's registration is closed, the system archives the registered participants for that run. You can download the reports as clean Excel-compatible sheets or styled PDF records here.
                        </p>
                        
                        <?php if (empty($reports)): ?>
                            <div style="text-align: center; padding: 3rem; color: var(--text-secondary);">
                                <i class="bi bi-file-earmark-excel" style="font-size: 3rem; color: var(--text-muted); display: block; margin-bottom: 1rem;"></i>
                                <p>No exported reports found. Reports are automatically generated when you close registrations for an event in the <strong>Manage Events</strong> tab.</p>
                            </div>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="data-table">
                                    <thead>
                                        <tr>
                                            <th>Event Title</th>
                                            <th>Closed/Generated At</th>
                                            <th>Total Registrations</th>
                                            <th>Excel Sheet</th>
                                            <th>PDF Report</th>
                                            <th style="text-align: right;">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($reports as $rep): ?>
                                            <tr>
                                                <td>
                                                    <strong style="color: var(--text-primary);"><?php echo htmlspecialchars($rep['event_title']); ?></strong>
                                                    <div style="font-size: 0.8rem; color: var(--text-secondary); margin-top: 0.2rem;">Batch ID: #<?php echo $rep['id']; ?></div>
                                                </td>
                                                <td style="font-size: 0.9rem;">
                                                    <?php echo date('M d, Y, h:i A', strtotime($rep['generated_at'])); ?>
                                                </td>
                                                <td>
                                                    <span style="font-weight: 700; color: var(--primary);"><?php echo $rep['registration_count']; ?></span> students
                                                </td>
                                                <td>
                                                    <a href="download_report.php?id=<?php echo $rep['id']; ?>&type=excel" class="btn btn-success" style="padding: 0.4rem 0.8rem; font-size: 0.8rem; display: inline-flex; align-items: center; gap: 0.25rem; text-decoration: none;">
                                                        <i class="bi bi-file-earmark-spreadsheet-fill"></i> Download Excel
                                                    </a>
                                                </td>
                                                <td>
                                                    <a href="download_report.php?id=<?php echo $rep['id']; ?>&type=pdf" class="btn btn-primary" style="padding: 0.4rem 0.8rem; font-size: 0.8rem; display: inline-flex; align-items: center; gap: 0.25rem; text-decoration: none;">
                                                        <i class="bi bi-file-pdf-fill"></i> Download PDF
                                                    </a>
                                                </td>
                                                <td style="text-align: right;">
                                                    <form action="admin_dashboard.php" method="POST" style="margin: 0;" onsubmit="return confirm('Are you sure you want to delete this report batch? This will delete the saved Excel and PDF files from disk.');">
                                                        <input type="hidden" name="report_id" value="<?php echo $rep['id']; ?>">
                                                        <button type="submit" name="action_delete_report" class="btn btn-danger" style="padding: 0.4rem 0.8rem; font-size: 0.8rem; display: inline-flex; align-items: center; gap: 0.25rem;">
                                                            <i class="bi bi-trash-fill"></i> Delete
                                                        </button>
                                                    </form>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div> <!-- End of tab-export-reports -->
            </div> <!-- End of admin-content-inner -->
        </main> <!-- End of admin-main -->
    </div> <!-- End of admin-layout -->

    <!-- Payment Screenshot Lightbox Modal -->
    <div id="lightbox-modal" class="modal" onclick="closeLightbox()">
        <div class="modal-content" style="background: transparent; border: none; box-shadow: none; max-width: 800px; display: flex; justify-content: center; align-items: center;" onclick="event.stopPropagation()">
            <span style="position: absolute; top: -2.5rem; right: 0; font-size: 2rem; color: white; cursor: pointer;" onclick="closeLightbox()">&times;</span>
            <img id="lightbox-img" src="" alt="Full size receipt" style="max-width: 100%; max-height: 80vh; border-radius: var(--radius-sm); border: 2px solid white; box-shadow: 0 10px 40px rgba(0,0,0,0.8); object-fit: contain;">
        </div>
    </div>

    <!-- Add Event Modal -->
    <div id="add-event-modal" class="modal" onclick="closeAddEventModal()">
        <div class="modal-content" onclick="event.stopPropagation()" style="max-width: 600px;">
            <div class="modal-header">
                <h3 class="modal-title">Create New Event</h3>
                <button class="modal-close" onclick="closeAddEventModal()">&times;</button>
            </div>
            <form action="admin_dashboard.php" method="POST">
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label" for="event-title-input">Event Title</label>
                        <input type="text" id="event-title-input" name="title" class="form-control" placeholder="E.g. AI & Machine Learning Symposium" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="event-desc-input">Event Description</label>
                        <textarea id="event-desc-input" name="description" class="form-control" rows="4" placeholder="Detail the event description, schedule, benefits..." style="resize: vertical;" required></textarea>
                    </div>
                    
                    <div class="form-group" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div>
                            <label class="form-label" for="event-date-input">Event Date & Time</label>
                            <input type="datetime-local" id="event-date-input" name="event_date" class="form-control" required>
                        </div>
                        <div>
                            <label class="form-label" for="event-location-input">Event Location</label>
                            <input type="text" id="event-location-input" name="location" class="form-control" placeholder="E.g. Seminar Hall B" required>
                        </div>
                    </div>
                    
                    <div class="form-group" style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                        <div>
                            <label class="form-label" for="event-type-select">Event Type</label>
                            <select id="event-type-select" name="event_type" class="form-control" onchange="togglePriceInput(this.value)" required>
                                <option value="free" selected>Free Entry</option>
                                <option value="paid">Paid Ticket</option>
                            </select>
                        </div>
                        <div id="price-input-container" style="display: none;">
                            <label class="form-label" for="event-price-input">Ticket Price ($)</label>
                            <input type="number" id="event-price-input" name="price" class="form-control" placeholder="0.00" step="0.01" min="0.01" value="0.00">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="event-image-input">Event Image URL (Optional)</label>
                        <input type="text" id="event-image-input" name="image_url" class="form-control" placeholder="E.g. images/hackathon.jpg">
                    </div>
                    
                    <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 2rem;">
                        <button type="button" class="btn btn-secondary" onclick="closeAddEventModal()">Cancel</button>
                        <button type="submit" name="action_add_event" class="btn btn-primary">Create Event</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        // Tab switching
        function switchTab(tabId, button) {
            document.querySelectorAll('.tab-pane').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.sidebar-link').forEach(link => {
                link.classList.remove('active');
            });
            
            document.getElementById('tab-' + tabId).classList.add('active');
            if (button) {
                button.classList.add('active');
            } else {
                const btn = Array.from(document.querySelectorAll('.sidebar-link')).find(b => b.getAttribute('onclick') && b.getAttribute('onclick').includes("'" + tabId + "'"));
                if (btn) btn.classList.add('active');
            }
            
            // Update page header title
            const titles = {
                'dashboard-overview': 'Dashboard Overview',
                'manage-regs': 'Registration Manager',
                'manage-helpers': 'Helper Manager',
                'manage-events': 'Manage Events',
                'manage-activities': 'Activities Manager',
                'feedback-mgmt': 'Feedback Management',
                'system-logs': 'System Audit Logs',
                'cert-generator': 'Certificate Generator',
                'export-reports': 'Exported Reports Archive'
            };
            const pageTitleEl = document.getElementById('admin-current-tab-title');
            if (pageTitleEl && titles[tabId]) {
                pageTitleEl.textContent = titles[tabId];
            }
            
            localStorage.setItem('admin_active_tab', tabId);
        }

        // Restore active tab on load
        document.addEventListener('DOMContentLoaded', () => {
            const activeTab = localStorage.getItem('admin_active_tab') || 'dashboard-overview';
            if (activeTab && document.getElementById('tab-' + activeTab)) {
                switchTab(activeTab, null);
            }
        });

        // Image Lightbox Viewer
        function openLightbox(src) {
            document.getElementById('lightbox-img').src = src;
            document.getElementById('lightbox-modal').classList.add('active');
        }

        function closeLightbox() {
            document.getElementById('lightbox-modal').classList.remove('active');
        }

        // Add Event Modal open/close
        function openAddEventModal() {
            document.getElementById('add-event-modal').classList.add('active');
        }

        function closeAddEventModal() {
            document.getElementById('add-event-modal').classList.remove('active');
            // reset form fields
            document.getElementById('event-title-input').value = '';
            document.getElementById('event-desc-input').value = '';
            document.getElementById('event-date-input').value = '';
            document.getElementById('event-location-input').value = '';
            document.getElementById('event-type-select').value = 'free';
            togglePriceInput('free');
            document.getElementById('event-image-input').value = '';
        }

        function togglePriceInput(type) {
            const container = document.getElementById('price-input-container');
            const priceInput = document.getElementById('event-price-input');
            if (type === 'paid') {
                container.style.display = 'block';
                priceInput.required = true;
                priceInput.min = '0.01';
                priceInput.value = '';
            } else {
                container.style.display = 'none';
                priceInput.required = false;
                priceInput.min = '0';
                priceInput.value = '0.00';
            }
        }

        // Copy text helper
        function copyTextValue(inputId, buttonId) {
            const copyText = document.getElementById(inputId);
            copyText.select();
            copyText.setSelectionRange(0, 99999); // For mobile devices
            
            navigator.clipboard.writeText(copyText.value).then(() => {
                const btn = document.getElementById(buttonId);
                const originalText = btn.textContent;
                btn.textContent = 'Copied! ✅';
                setTimeout(() => {
                    btn.textContent = originalText;
                }, 2000);
            }).catch(err => {
                console.error("Copy failed", err);
                alert("Failed to copy. Please select the text and copy manually.");
            });
        }

        // Helper Scanner QR Modal functions
        function openQRModal(helperName, helperKey) {
            // If accessed via localhost or 127.0.0.1, swap origin with active tunnel / LAN IP to be shareable
            let baseUrl = window.location.href.split('?')[0].replace('admin_dashboard.php', 'mobile_scanner.php');
            if (window.location.hostname.toLowerCase() === 'localhost' || window.location.hostname === '127.0.0.1') {
                const detectedBase = <?php echo json_encode($detected_base_url); ?>;
                baseUrl = detectedBase + '/event/mobile_scanner.php';
            }
            const scannerUrl = baseUrl + '?helper_key=' + helperKey;
            
            document.getElementById('qr-modal-subtitle').textContent = 'Scan to open scanner for ' + helperName;
            document.getElementById('qr-modal-url').value = scannerUrl;
            
            // Set QR Code image using qrserver API
            const qrImgUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' + encodeURIComponent(scannerUrl);
            document.getElementById('qr-code-img').src = qrImgUrl;
            
            document.getElementById('helper-qr-modal').classList.add('active');
        }

        function closeQRModal() {
            document.getElementById('helper-qr-modal').classList.remove('active');
        }

        function copyQRUrl() {
            const copyText = document.getElementById('qr-modal-url');
            copyText.select();
            copyText.setSelectionRange(0, 99999);
            
            navigator.clipboard.writeText(copyText.value).then(() => {
                const btn = document.getElementById('qr-copy-url-btn');
                const originalText = btn.textContent;
                btn.textContent = 'Copied! ✅';
                setTimeout(() => {
                    btn.textContent = originalText;
                }, 2000);
            }).catch(err => {
                console.error("Copy failed", err);
                alert("Failed to copy. Please select the text and copy manually.");
            });
        }

        // Live table filtering for registrations
        function filterRegistrations() {
            const query = document.getElementById('reg-search').value.toLowerCase().trim();
            const rows = document.querySelectorAll('#tab-manage-regs tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                if (text.includes(query)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        // Live table filtering for events
        function filterEvents() {
            const query = document.getElementById('event-search').value.toLowerCase().trim();
            const rows = document.querySelectorAll('#tab-manage-events tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                if (text.includes(query)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        // Live table filtering for system logs
        function filterSystemLogs() {
            const query = document.getElementById('log-search').value.toLowerCase().trim();
            const rows = document.querySelectorAll('#tab-system-logs tbody tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                if (text.includes(query)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }

        // Live table filtering and rating selection for feedbacks
        function filterFeedbacks() {
            const query = document.getElementById('feedback-search').value.toLowerCase().trim();
            const ratingFilter = document.getElementById('rating-filter').value;
            const rows = document.querySelectorAll('#tab-feedback-mgmt tbody tr');
            
            rows.forEach(row => {
                const nameEl = row.querySelector('.feedback-student-name');
                const eventEl = row.querySelector('.feedback-event-title');
                const name = nameEl ? nameEl.textContent.toLowerCase() : '';
                const eventTitle = eventEl ? eventEl.textContent.toLowerCase() : '';
                const rating = row.getAttribute('data-rating');
                
                const matchesQuery = name.includes(query) || eventTitle.includes(query);
                const matchesRating = (ratingFilter === 'all') || (rating === ratingFilter);
                
                if (matchesQuery && matchesRating) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        }



        // ─── Dynamic admin welcome greeting ──────────────────────────
        document.addEventListener('DOMContentLoaded', () => {
            const greetingEl = document.getElementById('welcome-greeting');
            if (greetingEl) {
                const hour = new Date().getHours();
                let greeting = 'Welcome';
                if (hour < 12) {
                    greeting = 'Good Morning';
                } else if (hour < 17) {
                    greeting = 'Good Afternoon';
                } else {
                    greeting = 'Good Evening';
                }
                greetingEl.textContent = greeting + ', <?php echo htmlspecialchars(addslashes($_SESSION['user_name'] ?? 'Administrator')); ?>! 🛡️';
            }
        });
    </script>
</body>
</html>

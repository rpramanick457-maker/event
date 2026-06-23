<?php
ob_start();
require_once 'db_connect.php';
require_once 'otp_handler.php';
require_once 'security.php';

$detected_base_url = getActiveTunnelOrLanUrl();

// Check login status
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Redirect Admin to admin dashboard
if ($_SESSION['user_role'] === 'admin') {
    header('Location: admin_dashboard.php');
    exit();
}

$user_id = $_SESSION['user_id'];
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

// Process Password Change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_change_password'])) {
    $current_pass = $_POST['current_password'];
    $new_pass = $_POST['new_password'];
    $confirm_new_pass = $_POST['confirm_new_password'];
    
    if (empty($current_pass) || empty($new_pass) || empty($confirm_new_pass)) {
        $error = 'All fields are required.';
    } elseif ($new_pass !== $confirm_new_pass) {
        $error = 'New passwords do not match.';
    } elseif (strlen($new_pass) < 6) {
        $error = 'New password must be at least 6 characters.';
    } else {
        $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
        $stmt->execute([$user_id]);
        $user_hash = $stmt->fetchColumn();
        
        if (password_verify($current_pass, $user_hash)) {
            $new_hash = password_hash($new_pass, PASSWORD_DEFAULT);
            $update = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $update->execute([$new_hash, $user_id]);
            $_SESSION['success_message'] = 'Password updated successfully.';
            header('Location: dashboard.php');
            exit();
        } else {
            $error = 'Incorrect current password.';
        }
    }
}

// Process Event Registration Form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_register_event'])) {
    $event_id = intval($_POST['event_id']);
    $student_name = trim($_POST['student_name']);
    $roll_no = trim($_POST['roll_no']);
    $batch = trim($_POST['batch']);
    $stream = trim($_POST['stream']);
    $food_preference = $_POST['food_preference'];
    $event_role = $_POST['event_role'];
    
    if (empty($student_name) || empty($roll_no) || empty($batch) || empty($stream) || empty($food_preference) || empty($event_role)) {
        $error = 'All fields are required.';
    } else {
        $roll_no = strtoupper($roll_no);
        $valid_batches = ['2023-2027', '2024-2028'];
        $valid_streams = ['BCA', 'MCA'];
        $requiredPrefix = '';

        if ($batch === '2023-2027') {
            $requiredPrefix = '23BCA';
        } elseif ($batch === '2024-2028') {
            $requiredPrefix = '19BCA';
        }

        if (!in_array($batch, $valid_batches, true)) {
            $error = 'Please select a valid batch.';
        } elseif (!in_array($stream, $valid_streams, true)) {
            $error = 'Please select a valid stream.';
        } elseif ($requiredPrefix && stripos($roll_no, $requiredPrefix) !== 0) {
            $error = 'Roll number must start with ' . $requiredPrefix . ' for the selected batch.';
        } else {
            // Query event price to determine if it is free
            $stmt_price = $conn->prepare("SELECT price FROM events WHERE id = ?");
            $stmt_price->execute([$event_id]);
            $event_price_val = $stmt_price->fetchColumn();
            
            if ($event_price_val === false) {
                $error = 'Event not found.';
            } else {
                $is_free = (floatval($event_price_val) <= 0.00);
                
                if (!$is_free && (!isset($_FILES['payment_screenshot']) || $_FILES['payment_screenshot']['error'] !== UPLOAD_ERR_OK)) {
                    $error = 'Please upload a payment screenshot receipt.';
                } else {
                    // Check if already registered
                    $stmt = $conn->prepare("SELECT id, status FROM registrations WHERE user_id = ? AND event_id = ?");
                    $stmt->execute([$user_id, $event_id]);
                    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($existing) {
                        $error = 'You have already registered for this event (Status: ' . ucfirst($existing['status']) . ').';
                    } else {
                        $dest_path = 'free';
                        $upload_success = true;
                        
                        if (!$is_free) {
                            $file_tmp = $_FILES['payment_screenshot']['tmp_name'];
                            $file_name = $_FILES['payment_screenshot']['name'];
                            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                            $allowed_exts = ['jpg', 'jpeg', 'png', 'webp'];
                            
                            if (!in_array($file_ext, $allowed_exts)) {
                                $error = 'Only JPG, PNG, and WEBP screenshot files are allowed.';
                                $upload_success = false;
                            } else {
                                $upload_dir = 'uploads/payment_receipts';
                                if (!file_exists($upload_dir)) {
                                    mkdir($upload_dir, 0777, true);
                                }
                                
                                $new_file_name = 'receipt_' . $user_id . '_' . $event_id . '_' . time() . '.' . $file_ext;
                                $dest_path = $upload_dir . '/' . $new_file_name;
                                
                                if (!move_uploaded_file($file_tmp, $dest_path)) {
                                    $error = 'Failed to save the uploaded image. Check directory permissions.';
                                    $upload_success = false;
                                }
                            }
                        }
                        
                        if ($upload_success) {
                            // Encrypt sensitive student fields before storing
                            $enc_student_name   = encryptData($student_name);
                            $enc_roll_no        = encryptData($roll_no);
                            $enc_batch          = encryptData($batch);
                            $enc_stream         = encryptData($stream);
                            $enc_food_pref      = encryptData($food_preference);

                            // Insert registration
                            $stmt = $conn->prepare("INSERT INTO registrations (user_id, event_id, student_name, roll_no, batch, stream, food_preference, event_role, payment_screenshot, status, qr_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'inactive')");
                            $stmt->execute([
                                $user_id,
                                $event_id,
                                $enc_student_name,
                                $enc_roll_no,
                                $enc_batch,
                                $enc_stream,
                                $enc_food_pref,
                                $event_role,
                                $dest_path
                            ]);
                            $_SESSION['success_message'] = 'Event registration submitted successfully! Awaiting Administrator approval.';
                            header('Location: dashboard.php');
                            exit();
                        }
                    }
                }
            }
        }
    }
}

// Fetch all events (active only)
$events_stmt = $conn->query("SELECT * FROM events WHERE is_active = 1 ORDER BY event_date ASC");
$events = $events_stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch user registrations with event details
$reg_stmt = $conn->prepare("
    SELECT r.*, e.title as event_title, e.event_date, e.location, e.price 
    FROM registrations r 
    JOIN events e ON r.event_id = e.id 
    WHERE r.user_id = ? 
    ORDER BY r.created_at DESC
");
$reg_stmt->execute([$user_id]);
$registrations = $reg_stmt->fetchAll(PDO::FETCH_ASSOC);

// Decrypt sensitive fields for display
foreach ($registrations as &$r) {
    $r['student_name']   = decryptData($r['student_name']);
    $r['roll_no']        = decryptData($r['roll_no']);
    $r['batch']          = decryptData($r['batch']);
    $r['food_preference']= decryptData($r['food_preference']);
}
unset($r);

// Index registrations by event_id for button checks
$registered_events = [];
foreach ($registrations as $r) {
    $registered_events[$r['event_id']] = $r;
}

// Calculate user dashboard stats
$total_tickets = 0;
$pending_approvals = 0;
foreach ($registrations as $r) {
    if ($r['status'] === 'approved') {
        $total_tickets++;
    } elseif ($r['status'] === 'pending') {
        $pending_approvals++;
    }
}
$available_events_count = count($events);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Student Dashboard | Nexus</title>
    <link rel="stylesheet" href="css/style.css?v=1.6">
    <script src="js/theme.js?v=1.6"></script>
    <!-- QR Code generation library loaded via CDN -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
    
    <style>
        /* Premium Interactive Dashboard Styling */

        /* Stats Grid & Metric Cards */
        .stats-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2.5rem;
        }
        @media(max-width: 576px) {
            .stats-row {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
        }
        .metric-card {
            background: var(--bg-card-glass);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.5rem;
            display: flex;
            align-items: center;
            gap: 1.25rem;
            cursor: pointer;
            transition: var(--transition);
            box-shadow: 0 4px 15px rgba(0,0,0,0.02);
            position: relative;
            overflow: hidden;
        }
        .metric-card::after {
            content: '';
            position: absolute;
            bottom: 0; left: 0; right: 0;
            height: 3px;
            background: transparent;
            transition: var(--transition);
        }
        .metric-card:hover {
            transform: translateY(-4px);
            border-color: var(--primary);
            box-shadow: 0 8px 24px var(--primary-glow);
        }
        .metric-card:hover::after {
            background: var(--primary);
        }
        .metric-icon {
            font-size: 2rem;
            width: 50px;
            height: 50px;
            border-radius: 12px;
            background: var(--bg-input);
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--primary);
            transition: var(--transition);
        }
        .metric-card:hover .metric-icon {
            background: var(--primary);
            color: white;
            transform: scale(1.1) rotate(5deg);
        }
        .metric-info {
            display: flex;
            flex-direction: column;
        }
        .metric-number {
            font-size: 1.75rem;
            font-weight: 800;
            font-family: var(--font-heading);
            color: var(--text-primary);
            line-height: 1.2;
        }
        .metric-label {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--text-secondary);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        /* Interactive Filters */
        .filter-controls {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid var(--border-color);
        }
        .filter-tags {
            display: flex;
            gap: 0.5rem;
        }
        .filter-tag {
            background: var(--bg-input);
            border: 1px solid var(--border-color);
            color: var(--text-secondary);
            padding: 0.5rem 1rem;
            border-radius: 30px;
            font-size: 0.85rem;
            font-weight: 600;
            cursor: pointer;
            transition: var(--transition);
        }
        .filter-tag:hover, .filter-tag.active {
            border-color: var(--primary);
            color: var(--primary);
            background: var(--primary-glow);
        }

        /* Virtual Ticket Wallet Stub Layout */
        .tickets-deck {
            display: grid;
            grid-template-columns: 1fr;
            gap: 1.5rem;
        }
        .virtual-ticket {
            display: flex;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            overflow: hidden;
            box-shadow: 0 6px 20px rgba(0,0,0,0.03);
            transition: var(--transition);
            position: relative;
        }
        .virtual-ticket:hover {
            transform: translateY(-3px);
            border-color: var(--border-hover);
            box-shadow: 0 10px 25px rgba(0,0,0,0.08);
        }
        .ticket-main {
            flex-grow: 1;
            padding: 1.5rem;
            position: relative;
        }
        .ticket-stub {
            width: 180px;
            border-left: 2px dashed var(--border-color);
            padding: 1.5rem;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            text-align: center;
            background: rgba(255, 255, 255, 0.01);
            position: relative;
        }
        /* Ticket Circular Notches */
        .virtual-ticket::before, .virtual-ticket::after {
            content: '';
            position: absolute;
            left: calc(100% - 190px); /* aligned directly at the stub dashed line */
            width: 20px;
            height: 20px;
            background: var(--bg-main);
            border: 1px solid var(--border-color);
            border-radius: 50%;
            z-index: 5;
        }
        .virtual-ticket::before {
            top: -11px;
            box-shadow: inset 0 -4px 5px rgba(0,0,0,0.02);
        }
        .virtual-ticket::after {
            bottom: -11px;
            box-shadow: inset 0 4px 5px rgba(0,0,0,0.02);
        }
        
        .ticket-event-tag {
            font-size: 0.75rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            color: var(--primary);
            margin-bottom: 0.5rem;
            display: inline-block;
        }
        .ticket-event-title {
            font-family: var(--font-heading);
            font-size: 1.35rem;
            font-weight: 800;
            margin-bottom: 0.75rem;
            color: var(--text-primary);
        }
        .ticket-details-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 1rem;
            margin-top: 1rem;
            padding-top: 1rem;
            border-top: 1px solid var(--border-color);
        }
        .ticket-detail-item {
            display: flex;
            flex-direction: column;
            gap: 0.2rem;
        }
        .ticket-detail-label {
            font-size: 0.75rem;
            color: var(--text-muted);
            text-transform: uppercase;
            font-weight: 600;
        }
        .ticket-detail-value {
            font-size: 0.9rem;
            font-weight: 700;
            color: var(--text-secondary);
        }
        .stub-title {
            font-size: 0.75rem;
            text-transform: uppercase;
            font-weight: 700;
            color: var(--text-muted);
            letter-spacing: 0.05em;
        }
        .stub-badge {
            margin-bottom: 0.5rem;
        }

        /* Interactive File Upload Zone */
        .dragzone {
            border: 2px dashed var(--border-color);
            border-radius: var(--radius-md);
            padding: 2rem;
            text-align: center;
            background: rgba(255,255,255,0.01);
            transition: var(--transition);
            cursor: pointer;
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 0.5rem;
        }
        .dragzone.dragover {
            border-color: var(--primary);
            background: var(--primary-glow);
            transform: scale(1.02);
        }
        
        @media(max-width: 768px) {
            .virtual-ticket {
                flex-direction: column;
            }
            .ticket-stub {
                width: 100%;
                border-left: none;
                border-top: 2px dashed var(--border-color);
            }
            .virtual-ticket::before, .virtual-ticket::after {
                display: none;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header>
        <div class="nav-container">
            <a href="index.php" class="logo-container">
                <img src="images/nexus_logo.png" alt="Nexus Logo" class="logo-img">
                <span class="logo-text">Nexus</span>
            </a>
            <ul class="nav-links">
                <li><a href="index.php">Home</a></li>
                <li><a href="dashboard.php" class="active">Dashboard</a></li>
                <li><a href="logout.php" class="nav-btn">Logout</a></li>
                <li><button class="theme-toggle-btn" aria-label="Toggle Theme">🌙</button></li>
            </ul>
        </div>
    </header>

    <div class="container">
        <!-- Message Toasts -->
        <?php if (!empty($success)): ?>
            <div class="alert alert-success">
                <span>✅</span> <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger">
                <span>⚠️</span> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <div class="dashboard-grid">
            <!-- Sidebar Panel -->
            <div class="sidebar">
                <div class="sidebar-card">
                    <div class="profile-avatar">
                        <?php echo strtoupper(substr($_SESSION['user_name'], 0, 1)); ?>
                    </div>
                    <div class="profile-info">
                        <div class="profile-name"><?php echo htmlspecialchars($_SESSION['user_name']); ?></div>
                        <div class="profile-role">Student Account</div>
                    </div>
                </div>
                
                <div class="sidebar-card" style="padding: 1rem;">
                    <div class="sidebar-nav">
                        <button class="sidebar-link active" onclick="switchTab('events', this)">
                            <span>📅</span> Browse Events
                        </button>
                        <button class="sidebar-link" onclick="switchTab('my-registrations', this)">
                            <span>🎫</span> My Tickets (<?php echo count($registrations); ?>)
                        </button>
                        <button class="sidebar-link" onclick="switchTab('my-certificates', this)">
                            <span>🎓</span> My Certificates
                        </button>
                        <button class="sidebar-link" onclick="switchTab('profile', this)">
                            <span>👤</span> Edit Profile
                        </button>
                    </div>
                </div>
            </div>

            <!-- Dashboard Content Panels -->
            <div class="dashboard-content">
                
                <!-- Tab: Browse Events -->
                <div id="tab-events" class="tab-pane active">
                    <!-- Futuristic Welcome Banner -->
                    <div class="welcome-banner">
                        <div class="welcome-text">
                            <h2 id="welcome-greeting">Welcome, <?php echo htmlspecialchars($_SESSION['user_name']); ?>!</h2>
                            <p>Ready to level up your student experience? Reserve your seats for the upcoming campus events below.</p>
                        </div>
                        <div style="font-size: 3.5rem; filter: drop-shadow(0 4px 8px var(--primary-glow));">🚀</div>
                    </div>

                    <!-- Metrics Dashboard Overview Cards -->
                    <div class="stats-row">
                        <div class="metric-card" onclick="switchTab('my-registrations', document.querySelector('[onclick*=\'my-registrations\']'))">
                            <div class="metric-icon">🎫</div>
                            <div class="metric-info">
                                <span class="metric-number"><?php echo $total_tickets; ?></span>
                                <span class="metric-label">My Active Tickets</span>
                            </div>
                        </div>
                        <div class="metric-card" onclick="switchTab('my-registrations', document.querySelector('[onclick*=\'my-registrations\']'))">
                            <div class="metric-icon">⏳</div>
                            <div class="metric-info">
                                <span class="metric-number"><?php echo $pending_approvals; ?></span>
                                <span class="metric-label">Awaiting Approval</span>
                            </div>
                        </div>
                        <div class="metric-card" onclick="document.getElementById('event-search').focus()">
                            <div class="metric-icon">✨</div>
                            <div class="metric-info">
                                <span class="metric-number"><?php echo $available_events_count; ?></span>
                                <span class="metric-label">Campus Events</span>
                            </div>
                        </div>
                    </div>

                    <div class="glass-panel" style="padding: 2rem;">
                        <!-- Filter Controls -->
                        <div class="filter-controls">
                            <div class="search-box-wrapper">
                                <span class="search-icon">🔍</span>
                                <input type="text" id="event-search" class="search-input" placeholder="Search events by title, location..." onkeyup="filterEvents()">
                            </div>
                            <div class="filter-tags">
                                <button class="filter-tag active" onclick="filterTag('all', this)">All</button>
                                <button class="filter-tag" onclick="filterTag('free', this)">Free</button>
                                <button class="filter-tag" onclick="filterTag('paid', this)">Paid</button>
                                <button class="filter-tag" onclick="filterTag('registered', this)">Registered</button>
                            </div>
                        </div>
                        
                        <div class="events-grid" id="events-list-container">
                            <?php foreach ($events as $event): 
                                $is_reg = isset($registered_events[$event['id']]);
                                $reg_status_val = $is_reg ? $registered_events[$event['id']]['status'] : 'none';
                            ?>
                                <div class="event-card event-item-card" 
                                     data-title="<?php echo htmlspecialchars(strtolower($event['title'])); ?>" 
                                     data-location="<?php echo htmlspecialchars(strtolower($event['location'])); ?>" 
                                     data-price="<?php echo $event['price']; ?>"
                                     data-registered="<?php echo $is_reg ? 'true' : 'false'; ?>">
                                    <div class="event-img <?php echo empty($event['image_url']) ? 'placeholder-img' : ''; ?>" <?php echo !empty($event['image_url']) ? 'style="background-image: url(\'' . htmlspecialchars($event['image_url']) . '\');"' : ''; ?>>
                                        <div class="event-price-tag">$<?php echo number_format($event['price'], 2); ?></div>
                                    </div>
                                    <div class="event-details">
                                        <h3 class="event-title"><?php echo htmlspecialchars($event['title']); ?></h3>
                                        <p class="event-desc"><?php echo htmlspecialchars($event['description']); ?></p>
                                        
                                        <div class="event-meta">
                                            <span>📅 <?php echo date('M d, Y', strtotime($event['event_date'])); ?></span>
                                            <span>📍 <?php echo htmlspecialchars($event['location']); ?></span>
                                        </div>
                                        
                                        <?php if ($is_reg): ?>
                                            <?php 
                                            $reg = $registered_events[$event['id']];
                                            if ($reg['status'] === 'pending') {
                                                echo '<button class="btn-card" style="border-color: var(--warning); color: #fbbf24; cursor: default;" disabled>⏳ Awaiting Approval</button>';
                                            } elseif ($reg['status'] === 'approved') {
                                                echo '<button class="btn-card" onclick="openTicketModal(' . $reg['id'] . ', \'' . htmlspecialchars(addslashes($event['title'])) . '\', \'' . $reg['qr_token'] . '\', \'' . $reg['qr_status'] . '\')" style="border-color: var(--success); color: #34d399; margin-bottom: 0.5rem; display: block; width: 100%;">🎟️ View QR Ticket</button>';
                                                echo '<button class="btn-card" onclick="openActivitiesModal(' . $event['id'] . ', ' . $reg['id'] . ', \'' . htmlspecialchars(addslashes($event['title'])) . '\')" style="border-color: var(--secondary); color: var(--text-primary); display: block; width: 100%;">🎭 Register for Activities</button>';
                                            } else {
                                                echo '<button class="btn-card" style="border-color: var(--danger); color: #fca5a5; cursor: default;" disabled>❌ Rejected</button>';
                                            }
                                            ?>
                                        <?php elseif ($event['reg_status'] === 'closed'): ?>
                                            <button class="btn btn-secondary btn-full" style="opacity: 0.6; cursor: not-allowed;" disabled>
                                                Registrations Closed
                                            </button>
                                        <?php else: ?>
                                            <button class="btn btn-primary" onclick="openRegisterModal(<?php echo $event['id']; ?>, '<?php echo htmlspecialchars(addslashes($event['title'])); ?>', <?php echo $event['price']; ?>)">
                                                Register Now
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <!-- Tab: My Registrations -->
                <div id="tab-my-registrations" class="tab-pane">
                    <div class="glass-panel" style="padding: 2rem;">
                        <h2>My Event Registrations</h2>
                        <p style="color: var(--text-secondary); margin-bottom: 2rem;">Track approval status, download invoice receipts, or display access QR tickets.</p>
                        
                        <?php if (empty($registrations)): ?>
                            <div style="text-align: center; padding: 3rem 1rem; color: var(--text-muted);">
                                <span style="font-size: 3rem;">🎫</span>
                                <h3 style="margin-top: 1rem; color: var(--text-secondary);">No registrations yet</h3>
                                <p style="font-size: 0.9rem; margin-top: 0.5rem;">Register for upcoming events in the Browse tab.</p>
                            </div>
                        <?php else: ?>
                            <div class="tickets-deck">
                                <?php foreach ($registrations as $r): 
                                    // Custom theme style depending on status
                                    $accent_color = '';
                                    if ($r['status'] === 'approved') {
                                        $accent_color = 'var(--success)';
                                    } elseif ($r['status'] === 'pending') {
                                        $accent_color = 'var(--warning)';
                                    } else {
                                        $accent_color = 'var(--danger)';
                                    }
                                ?>
                                    <div class="virtual-ticket" style="border-left: 5px solid <?php echo $accent_color; ?>;">
                                        <div class="ticket-main">
                                            <span class="ticket-event-tag">⚡ Event Access Pass</span>
                                            <h3 class="ticket-event-title"><?php echo htmlspecialchars($r['event_title']); ?></h3>
                                            
                                            <div class="ticket-details-grid">
                                                <div class="ticket-detail-item">
                                                    <span class="ticket-detail-label">Date & Time</span>
                                                    <span class="ticket-detail-value"><?php echo date('M d, Y H:i', strtotime($r['event_date'])); ?></span>
                                                </div>
                                                <div class="ticket-detail-item">
                                                    <span class="ticket-detail-label">Venue / Location</span>
                                                    <span class="ticket-detail-value">📍 <?php echo htmlspecialchars($r['location']); ?></span>
                                                </div>
                                                <div class="ticket-detail-item">
                                                    <span class="ticket-detail-label">Participant</span>
                                                    <span class="ticket-detail-value">👤 <?php echo htmlspecialchars($r['student_name']); ?></span>
                                                </div>
                                                <div class="ticket-detail-item">
                                                    <span class="ticket-detail-label">Academic Info</span>
                                                    <span class="ticket-detail-value">🎓 <?php echo htmlspecialchars($r['roll_no']); ?> (<?php echo htmlspecialchars($r['batch']); ?>)</span>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <div class="ticket-stub">
                                            <span class="stub-title">Access Status</span>
                                            <div class="stub-badge">
                                                <?php if ($r['status'] === 'pending'): ?>
                                                    <span class="badge badge-pending">Pending Approval</span>
                                                <?php elseif ($r['status'] === 'approved'): ?>
                                                    <span class="badge badge-approved">Approved</span>
                                                <?php else: ?>
                                                    <span class="badge badge-rejected">Rejected</span>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <div class="stub-badge" style="margin-top: 0.25rem;">
                                                <?php if ($r['qr_status'] === 'inactive'): ?>
                                                    <span class="badge badge-pending">Not Checked In</span>
                                                <?php elseif ($r['qr_status'] === 'active'): ?>
                                                    <span class="badge badge-active">Checked In</span>
                                                <?php else: ?>
                                                    <span class="badge badge-deactivated">Checked Out</span>
                                                <?php endif; ?>
                                            </div>
                                            
                                            <div style="margin-top: auto; width: 100%;">
                                                <?php if ($r['status'] === 'approved'): ?>
                                                    <button class="btn btn-primary btn-full" style="padding: 0.5rem; font-size: 0.85rem; background: var(--primary); border-color: var(--primary);" 
                                                            onclick="openTicketModal(<?php echo $r['id']; ?>, '<?php echo htmlspecialchars(addslashes($r['event_title'])); ?>', '<?php echo $r['qr_token']; ?>', '<?php echo $r['qr_status']; ?>')">
                                                        🎟️ Show QR
                                                    </button>
                                                <?php else: ?>
                                                    <button class="btn btn-secondary btn-full" style="padding: 0.5rem; font-size: 0.85rem; opacity: 0.5; cursor: not-allowed;" disabled>
                                                        🔒 Locked
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Tab: My Certificates -->
                <div id="tab-my-certificates" class="tab-pane">
                    <div class="glass-panel" style="padding: 2rem;">
                        <h2>My Certificates</h2>
                        <p style="color: var(--text-secondary); margin-bottom: 2rem;">Access and download official participation/organiser certificates for events you attended.</p>
                        
                        <?php
                        // Fetch all approved registrations with certificate info
                        $stmt_user_certs = $conn->prepare("
                            SELECT r.id as reg_id, r.event_role, r.batch, e.id as event_id, e.title as event_title, e.event_date, e.location, e.image_url, e.certificate_published,
                                   c.id as cert_id, c.pdf_path, c.qr_path, c.created_at as cert_created_at
                            FROM registrations r
                            JOIN events e ON r.event_id = e.id
                            LEFT JOIN certificates c ON r.user_id = c.user_id AND r.event_id = c.event_id
                            WHERE r.user_id = ? AND r.status = 'approved'
                            ORDER BY e.event_date DESC
                        ");
                        $stmt_user_certs->execute([$_SESSION['user_id']]);
                        $user_event_certs = $stmt_user_certs->fetchAll(PDO::FETCH_ASSOC);
                        
                        if (empty($user_event_certs)):
                        ?>
                            <div style="text-align: center; padding: 4rem 1rem; color: var(--text-muted);">
                                <span style="font-size: 3.5rem;">🎓</span>
                                <h3 style="margin-top: 1rem; color: var(--text-secondary);">No Certificates Available</h3>
                                <p style="font-size: 0.9rem; margin-top: 0.5rem; color: var(--text-muted);">You will receive certificates once you register for events, get approved, and the admin generates them.</p>
                            </div>
                        <?php else: ?>
                            <div class="events-grid" style="gap: 1.5rem;">
                                <?php foreach ($user_event_certs as $uc): 
                                    $has_cert = !empty($uc['cert_id']) && file_exists($uc['pdf_path']);
                                    $is_published = ($uc['certificate_published'] == 1);
                                ?>
                                    <div class="glass-card event-card" style="display: flex; flex-direction: column; overflow: hidden; height: 100%; transition: var(--transition); border: 1px solid var(--border-color); border-radius: var(--radius-md); background: var(--bg-card-glass);">
                                        <div class="event-image-container" style="position: relative; height: 140px; overflow: hidden; background: #000;">
                                            <img src="<?php echo htmlspecialchars($uc['image_url'] ?: 'images/event-placeholder.jpg'); ?>" alt="Event Image" style="width: 100%; height: 100%; object-fit: cover; opacity: 0.75;">
                                            <div style="position: absolute; top: 10px; right: 10px; z-index: 5;">
                                                <?php if ($has_cert): ?>
                                                    <span class="badge badge-approved" style="background: rgba(16, 185, 129, 0.25); color: #34d399; font-weight: 600;">Published</span>
                                                <?php else: ?>
                                                    <span class="badge badge-pending" style="background: rgba(245, 158, 11, 0.25); color: #fbbf24; font-weight: 600;">Processing</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        
                                        <div class="event-details" style="padding: 1.25rem; display: flex; flex-direction: column; flex-grow: 1;">
                                            <h3 style="font-size: 1.1rem; color: var(--text-primary); margin-bottom: 0.5rem; font-weight: 700;"><?php echo htmlspecialchars($uc['event_title']); ?></h3>
                                            <div style="font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 1rem;">
                                                📅 <?php echo date('M d, Y', strtotime($uc['event_date'])); ?> | 📍 <?php echo htmlspecialchars($uc['location']); ?>
                                            </div>
                                            
                                            <div style="margin-top: auto; border-top: 1px solid var(--border-color); padding-top: 1rem;">
                                                <?php if ($has_cert): ?>
                                                    <!-- Expandable Certificate Details / Preview -->
                                                    <div style="background: rgba(255,255,255,0.02); border: 1px solid var(--border-color); border-radius: var(--radius-sm); padding: 0.75rem; margin-bottom: 1rem; text-align: center;">
                                                        <div style="font-size: 0.8rem; color: var(--text-secondary); margin-bottom: 0.5rem;">Verified Certificate QR Code</div>
                                                        <img src="<?php echo htmlspecialchars($uc['qr_path']); ?>" alt="Certificate QR" style="width: 70px; height: 70px; background: white; padding: 4px; border-radius: var(--radius-sm); margin: 0 auto; display: block;">
                                                        <div style="font-family: monospace; font-size: 0.75rem; color: var(--primary); margin-top: 0.5rem; font-weight: bold;">
                                                            ID: EVT/<?php echo $uc['event_id']; ?>/<?php echo $uc['cert_id']; ?>
                                                        </div>
                                                    </div>
                                                    <a href="<?php echo htmlspecialchars($uc['pdf_path']); ?>" target="_blank" class="btn btn-primary btn-full" style="padding: 0.6rem; text-decoration: none; text-align: center; display: block; font-weight: 600;">
                                                        📥 Download Certificate (PDF)
                                                    </a>
                                                <?php else: ?>
                                                    <div style="text-align: center; padding: 1rem; color: var(--text-muted); font-size: 0.9rem; border: 1px dashed var(--border-color); border-radius: var(--radius-sm);">
                                                        <span style="font-size: 1.5rem; display: block; margin-bottom: 0.25rem;">⏳</span>
                                                        <strong>Not Available Yet</strong>
                                                        <div style="font-size: 0.75rem; color: var(--text-secondary); margin-top: 0.25rem;">The organizer has not yet generated/released the certificate for this event.</div>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Tab: Profile Settings -->
                <div id="tab-profile" class="tab-pane">
                    <div class="glass-panel" style="padding: 2.5rem; max-width: 600px;">
                        <h2>Profile Settings</h2>
                        <p style="color: var(--text-secondary); margin-bottom: 2rem;">Manage your credentials and change passwords.</p>
                        
                        <div class="details-row">
                            <span class="details-label">Full Name</span>
                            <span class="details-value"><?php echo htmlspecialchars($_SESSION['user_name']); ?></span>
                        </div>
                        <div class="details-row">
                            <span class="details-label">Email Address</span>
                            <span class="details-value"><?php echo htmlspecialchars($_SESSION['user_email']); ?></span>
                        </div>
                        
                        <h3 style="margin-top: 2.5rem; margin-bottom: 1rem; border-bottom: 1px solid var(--border-color); padding-bottom: 0.5rem;">Change Password</h3>
                        
                        <form action="dashboard.php" method="POST">
                            <div class="form-group">
                                <label class="form-label" for="current_password">Current Password</label>
                                <input type="password" id="current_password" name="current_password" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="new_password">New Password</label>
                                <input type="password" id="new_password" name="new_password" class="form-control" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label" for="confirm_new_password">Confirm New Password</label>
                                <input type="password" id="confirm_new_password" name="confirm_new_password" class="form-control" required>
                            </div>
                            
                            <button type="submit" name="action_change_password" class="btn btn-primary" style="margin-top: 1rem;">
                                Update Password
                            </button>
                        </form>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- Registration Modal Form -->
    <div id="register-modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title" id="modal-event-title">Register for Event</h3>
                <button class="modal-close" onclick="closeRegisterModal()">&times;</button>
            </div>
            <form action="dashboard.php" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="event_id" id="modal-event-id">
                
                <div class="modal-body">
                    <div class="form-group">
                        <label class="form-label">Event Price</label>
                        <div style="font-size: 1.25rem; font-weight: 800; color: var(--secondary);" id="modal-event-price">$0.00</div>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="student_name">Participant Full Name</label>
                        <input type="text" id="student_name" name="student_name" class="form-control" value="<?php echo htmlspecialchars($_SESSION['user_name']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label class="form-label" for="roll_no">Roll Number</label>
                        <input type="text" id="roll_no" name="roll_no" class="form-control" placeholder="23BCA000" required oninput="validateRollNumber(this)">
                        <div id="roll-error" style="display: none; color: #EA4335; font-size: 0.8rem; margin-top: 0.35rem; font-weight: 600;">⚠️ Roll number must match the selected batch prefix</div>
                    </div>
                    <div class="form-group form-row-grid">
                        <div>
                            <label class="form-label" for="batch">Batch / Year</label>
                            <select id="batch" name="batch" class="form-control batch-select" onchange="handleBatchChange()" required>
                                <option value="" selected disabled>Select Batch</option>
                                <option value="2023-2027">2023-2027</option>
                                <option value="2024-2028">2024-2028</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label" for="stream">Stream</label>
                            <select id="stream" name="stream" class="form-control" required>
                                <option value="" selected disabled>Select Stream</option>
                                <option value="BCA">BCA</option>
                                <option value="MCA">MCA</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-group form-row-grid">
                        <div>
                            <label class="form-label" for="food_preference">Food Preference</label>
                            <select id="food_preference" name="food_preference" class="form-control" required>
                                <option value="Veg">Vegetarian</option>
                                <option value="Non-Veg">Non-Vegetarian</option>
                                <option value="No Preference" selected>No Preference</option>
                            </select>
                        </div>
                        <div>
                            <label class="form-label" for="event_role">Role Selection</label>
                            <select id="event_role" name="event_role" class="form-control" required>
                                <option value="Participant" selected>Participant</option>
                                <option value="Volunteer">Volunteer Helper</option>
                                <option value="Organizer">Student Organizer</option>
                            </select>
                        </div>
                    </div>
                    
                    <!-- Payment QR Code section -->
                    <div id="payment-qr-section" style="display: none; flex-direction: column; align-items: center; gap: 0.75rem; padding: 1.5rem; background: rgba(255, 255, 255, 0.03); border-radius: var(--radius-md); border: 1px solid var(--border-color); margin-bottom: 1.5rem; text-align: center;">
                        <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 0.25rem;">
                            <!-- Avatar circle matching Rahul Pramanick -->
                            <div style="width: 36px; height: 36px; border-radius: 50%; background: linear-gradient(135deg, var(--primary), var(--secondary)); color: white; display: flex; align-items: center; justify-content: center; font-weight: 800; font-size: 0.95rem; box-shadow: 0 2px 8px var(--primary-glow);">RP</div>
                            <span style="font-size: 0.95rem; font-weight: 700; color: var(--text-primary);">Rahul Pramanick</span>
                        </div>
                        
                        <div style="position: relative; display: inline-block;">
                            <div id="payment-qrcode-box" class="qr-box" style="padding: 1rem; background: white; border-radius: var(--radius-md); box-shadow: 0 8px 25px rgba(0,0,0,0.15); display: inline-block;">
                                <!-- Rendered dynamically by QRCode.js -->
                            </div>
                            <!-- Small GPay stylized overlay in center -->
                            <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); width: 34px; height: 34px; background: white; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 6px rgba(0,0,0,0.15); border: 2px solid white; pointer-events: none; z-index: 10;">
                                <div style="display: flex; gap: 1.5px;">
                                    <span style="width: 4px; height: 13px; background: #4285F4; border-radius: 1.5px; transform: skewY(-15deg);"></span>
                                    <span style="width: 4px; height: 13px; background: #EA4335; border-radius: 1.5px; transform: skewY(15deg);"></span>
                                    <span style="width: 4px; height: 13px; background: #FBBC05; border-radius: 1.5px; transform: skewY(-15deg);"></span>
                                    <span style="width: 4px; height: 13px; background: #34A853; border-radius: 1.5px; transform: skewY(15deg);"></span>
                                </div>
                            </div>
                        </div>
                        
                        <div style="font-size: 0.85rem; color: var(--text-secondary); max-width: 380px; line-height: 1.4; margin-top: 0.25rem;">
                            <span style="font-size: 0.9rem; font-weight: 700; color: var(--text-primary); display: block; margin-bottom: 0.2rem;">UPI ID: rpramanick457@okhdfcbank</span>
                            Scan to pay <strong id="payment-qr-price-label" style="color: var(--primary);"></strong> with any UPI app
                        </div>
                    </div>

                    <div class="form-group" id="screenshot-upload-group">
                        <label class="form-label">Upload Payment Screenshot receipt</label>
                        <div class="file-upload-wrapper">
                            <div class="dragzone" id="payment-dragzone" onclick="triggerFileInput()">
                                <span class="file-upload-icon" id="dragzone-icon">📷</span>
                                <strong id="dragzone-text">Choose receipt image or drag here</strong>
                                <span style="font-size: 0.75rem; color: var(--text-muted);">Supports JPG, PNG, WEBP max 5MB</span>
                            </div>
                            <input type="file" id="payment_screenshot" name="payment_screenshot" class="file-upload-input" accept="image/*" required style="display: none;" onchange="previewScreenshot(this)">
                        </div>
                        <img id="screenshot-preview-el" class="screenshot-preview" src="#" alt="Screenshot preview">
                    </div>
                    
                    <div style="display: flex; gap: 1rem; justify-content: flex-end; margin-top: 2rem;">
                        <button type="button" class="btn btn-secondary" onclick="closeRegisterModal()">Cancel</button>
                        <button type="submit" name="action_register_event" class="btn btn-primary">Submit Registration</button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- QR Code Ticket Modal -->
    <div id="ticket-modal" class="modal">
        <div class="modal-content" style="max-width: 420px;">
            <div class="modal-header">
                <h3 class="modal-title">Your Event Ticket</h3>
                <button class="modal-close" onclick="closeTicketModal()">&times;</button>
            </div>
            <div class="modal-body" style="padding: 1.5rem;">
                <!-- Network warning for localhost access -->
                <div id="localhost-warning" style="display: none; background: rgba(239, 68, 68, 0.08); border: 1px dashed #FF6B35; border-radius: 8px; padding: 0.75rem; margin-bottom: 1rem; font-size: 0.78rem; color: #FFA852; text-align: left; line-height: 1.4;">
                    ⚠️ <strong>Scanner Notice:</strong> You are accessing this site via <code>localhost</code>. If volunteers scan this QR code using their mobile network, they will get a connection error. Please open the student dashboard using your public localtunnel link (e.g. <code>https://*.loca.lt</code>) so that the QR code is generated with a valid public address.
                </div>
                
                <div class="qr-container">
                    <h4 id="ticket-event-title" style="text-align: center; font-size: 1.2rem;">Event Title</h4>
                    
                    <div id="qrcode-box" class="qr-box">
                        <!-- Rendered by QRCode.js -->
                    </div>
                    
                    <div class="qr-info">
                        <div class="qr-ticket-id" id="ticket-token-label">TOKEN_ID</div>
                        <p style="font-size: 0.85rem; color: var(--text-secondary); margin-top: 0.5rem;">
                            Scan this QR code at the entrance and exit of the venue to mark your attendance.
                        </p>
                        <div id="ticket-status-badge" style="margin-top: 1rem;">
                            <!-- Set dynamically -->
                        </div>
                    </div>
                </div>
                
                <button class="btn btn-secondary btn-full" onclick="closeTicketModal()" style="margin-top: 1.5rem;">
                    Close Ticket
                </button>
            </div>
        </div>
    </div>

    <!-- Event Activities Modal -->
    <div id="activities-modal" class="modal">
        <div class="modal-content" style="max-width: 600px; max-height: 85vh; display: flex; flex-direction: column;">
            <div class="modal-header" style="flex-shrink: 0;">
                <h3 class="modal-title">🎭 Event Activities & Performances</h3>
                <button class="modal-close" onclick="closeActivitiesModal()">&times;</button>
            </div>
            <div class="modal-body" style="padding: 1.5rem; overflow-y: auto; flex-grow: 1;">
                <h4 id="act-modal-event-title" style="margin: 0 0 0.5rem 0; color: var(--primary); font-size: 1.2rem;">Event Title</h4>
                <p style="font-size: 0.85rem; color: var(--text-secondary); margin: 0 0 1.5rem 0; line-height: 1.4;">
                    Register for solo, duet or group activities. Note: Your partner or team members must be registered and approved for the event first.
                </p>
                
                <div id="act-loading-spinner" style="text-align: center; padding: 3rem 1rem;">
                    <span style="font-size: 2rem; display: inline-block; animation: spin 1s linear infinite;">⏳</span>
                    <p style="margin-top: 0.75rem; font-size: 0.9rem; color: var(--text-secondary);">Loading available activities...</p>
                </div>
                
                <div id="activities-list-container" style="display: none;">
                    <!-- Activities populated via AJAX -->
                </div>
            </div>
        </div>
    </div>

    <script>
        // Simple client-side tab switching logic
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
                // Find matching button if triggered programmatically
                const matchingBtn = document.querySelector(`[onclick*="switchTab('${tabId}'"]`);
                if (matchingBtn) matchingBtn.classList.add('active');
            }
        }

        // Event Registration Modal controls
        function openRegisterModal(eventId, eventTitle, price) {
            document.getElementById('modal-event-id').value = eventId;
            document.getElementById('modal-event-title').textContent = 'Register: ' + eventTitle;
            document.getElementById('modal-event-price').textContent = '$' + price.toFixed(2);
            
            const qrSection = document.getElementById('payment-qr-section');
            const uploadGroup = document.getElementById('screenshot-upload-group');
            const fileInput = document.getElementById('payment_screenshot');
            
            if (price > 0) {
                qrSection.style.display = 'flex';
                uploadGroup.style.display = 'block';
                fileInput.required = true;
                document.getElementById('payment-qr-price-label').textContent = '$' + price.toFixed(2);
                
                // Clear previous QR code content
                document.getElementById('payment-qrcode-box').innerHTML = '';
                
                // Construct UPI payment link payload with correct UPI ID and Name
                const upiLink = 'upi://pay?pa=rpramanick457@okhdfcbank&pn=Rahul%20Pramanick&am=' + price.toFixed(2) + '&tn=Registration%20for%20' + encodeURIComponent(eventTitle);
                
                // Generate the QR code dynamically using QRCode.js
                new QRCode(document.getElementById('payment-qrcode-box'), {
                    text: upiLink,
                    width: 180,
                    height: 180,
                    colorDark : "#130E0A",
                    colorLight : "#ffffff",
                    correctLevel : QRCode.CorrectLevel.M
                });
            } else {
                qrSection.style.display = 'none';
                uploadGroup.style.display = 'none';
                fileInput.required = false;
            }
            
            document.getElementById('register-modal').classList.add('active');
        }

        function getRequiredRollPrefix() {
            const batchSelect = document.getElementById('batch');
            if (!batchSelect) return '';
            const selectedBatch = batchSelect.value;
            if (selectedBatch === '2023-2027') return '23BCA';
            if (selectedBatch === '2024-2028') return '19BCA';
            return '';
        }

        function updateRollPlaceholder() {
            const rollInput = document.getElementById('roll_no');
            const prefix = getRequiredRollPrefix();
            if (rollInput) {
                rollInput.placeholder = prefix ? prefix + '000' : '23BCA000';
            }
        }

        function validateRollNumber(input) {
            const value = input.value.toUpperCase();
            input.value = value;
            const errorEl = document.getElementById('roll-error');
            const requiredPrefix = getRequiredRollPrefix();

            if (!value || !requiredPrefix) {
                errorEl.style.display = 'none';
                input.style.borderColor = '';
                return;
            }

            if (!value.startsWith(requiredPrefix)) {
                errorEl.textContent = '⚠️ Roll number must start with ' + requiredPrefix;
                errorEl.style.display = 'block';
                input.style.borderColor = '#EA4335';
            } else {
                errorEl.style.display = 'none';
                input.style.borderColor = '';
            }
        }

        function handleBatchChange() {
            updateRollPlaceholder();
            const rollInput = document.getElementById('roll_no');
            if (rollInput) validateRollNumber(rollInput);
        }
        
        function closeRegisterModal() {
            document.getElementById('register-modal').classList.remove('active');
            // reset file upload preview
            document.getElementById('screenshot-preview-el').style.display = 'none';
            document.getElementById('screenshot-preview-el').src = '#';
            document.getElementById('dragzone-text').textContent = 'Choose receipt image or drag here';
            document.getElementById('dragzone-icon').textContent = '📷';
            document.getElementById('payment_screenshot').value = '';
            // reset roll number validation
            const rollInput = document.getElementById('roll_no');
            if (rollInput) {
                rollInput.value = '';
                rollInput.style.borderColor = '';
                const rollError = document.getElementById('roll-error');
                if (rollError) rollError.style.display = 'none';
            }
        }

        function previewScreenshot(input) {
            if (input.files && input.files[0]) {
                const file = input.files[0];
                var reader = new FileReader();
                reader.onload = function(e) {
                    var img = document.getElementById('screenshot-preview-el');
                    img.src = e.target.result;
                    img.style.display = 'block';
                    img.style.animation = 'fadeIn 0.3s ease';
                    
                    const dragzoneText = document.getElementById('dragzone-text');
                    const dragzoneIcon = document.getElementById('dragzone-icon');
                    if (dragzoneText && dragzoneIcon) {
                        dragzoneText.textContent = 'Selected: ' + file.name;
                        dragzoneIcon.textContent = '✅';
                    }
                }
                reader.readAsDataURL(input.files[0]);
            }
        }

        // Drag and drop controls
        const dragzone = document.getElementById('payment-dragzone');
        const fileInput = document.getElementById('payment_screenshot');
        
        function triggerFileInput() {
            if (fileInput) fileInput.click();
        }
        
        if (dragzone && fileInput) {
            ['dragenter', 'dragover'].forEach(eventName => {
                dragzone.addEventListener(eventName, (e) => {
                    e.preventDefault();
                    dragzone.classList.add('dragover');
                }, false);
            });
            
            ['dragleave', 'drop'].forEach(eventName => {
                dragzone.addEventListener(eventName, (e) => {
                    e.preventDefault();
                    dragzone.classList.remove('dragover');
                }, false);
            });
            
            dragzone.addEventListener('drop', (e) => {
                const dt = e.dataTransfer;
                const files = dt.files;
                if (files.length > 0) {
                    fileInput.files = files;
                    previewScreenshot(fileInput);
                }
            }, false);
        }

        // QR Ticket Modal controls
        let activeQRInstance = null;
        
        function openTicketModal(regId, eventTitle, qrToken, qrStatus) {
            document.getElementById('ticket-event-title').textContent = eventTitle;
            document.getElementById('ticket-token-label').textContent = 'TKT-' + qrToken.substring(0, 8).toUpperCase();
            
            // Render Badge
            let badgeHtml = '';
            if (qrStatus === 'inactive') {
                badgeHtml = '<span class="badge badge-pending">Active (Not Checked In)</span>';
            } else if (qrStatus === 'active') {
                badgeHtml = '<span class="badge badge-approved">Checked In</span>';
            } else {
                badgeHtml = '<span class="badge badge-deactivated">Deactivated (Checked Out)</span>';
            }
            document.getElementById('ticket-status-badge').innerHTML = badgeHtml;
            
            // Clear previous QR Code
            document.getElementById('qrcode-box').innerHTML = '';
            
            // Show network warning if accessed via localhost/127.0.0.1 AND no active public tunnel is running
            const warningEl = document.getElementById('localhost-warning');
            if (warningEl) {
                const detectedBase = <?php echo json_encode($detected_base_url); ?>;
                const isLocalHost = (window.location.hostname.toLowerCase() === 'localhost' || window.location.hostname === '127.0.0.1');
                const isTunnelActive = detectedBase.startsWith('https://');
                if (isLocalHost && !isTunnelActive) {
                    warningEl.style.display = 'block';
                } else {
                    warningEl.style.display = 'none';
                }
            }

            // Generate QR Code containing the verification URL for phone's built-in camera
            let baseUrl = window.location.href.split('?')[0].replace('dashboard.php', 'quick_scan.php');
            // If accessed via localhost or 127.0.0.1, swap it with the detected tunnel or LAN IP
            if (window.location.hostname.toLowerCase() === 'localhost' || window.location.hostname === '127.0.0.1') {
                const detectedBase = <?php echo json_encode($detected_base_url); ?>;
                baseUrl = detectedBase + '/event/quick_scan.php';
            }
            const scanUrl = baseUrl + '?token=' + qrToken;
            activeQRInstance = new QRCode(document.getElementById("qrcode-box"), {
                text: scanUrl,
                width: 180,
                height: 180,
                colorDark : "#130E0A",
                colorLight : "#ffffff",
                correctLevel : QRCode.CorrectLevel.H
            });
            
            document.getElementById('ticket-modal').classList.add('active');
        }

        function closeTicketModal() {
            document.getElementById('ticket-modal').classList.remove('active');
        }
        
        // Activities Modal Logic
        let currentRegId = null;
        let currentEventId = null;
        
        function openActivitiesModal(eventId, regId, eventTitle) {
            currentRegId = regId;
            currentEventId = eventId;
            document.getElementById('act-modal-event-title').textContent = eventTitle;
            
            document.getElementById('act-loading-spinner').style.display = 'block';
            document.getElementById('activities-list-container').style.display = 'none';
            document.getElementById('activities-list-container').innerHTML = '';
            
            document.getElementById('activities-modal').classList.add('active');
            
            // Fetch activities
            fetch('activity_handler.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'get_activities',
                    event_id: eventId,
                    registration_id: regId
                })
            })
            .then(res => res.json())
            .then(data => {
                document.getElementById('act-loading-spinner').style.display = 'none';
                if (data.success) {
                    renderActivitiesList(data.activities);
                } else {
                    document.getElementById('activities-list-container').innerHTML = 
                        `<div class="alert alert-danger" style="margin-top:0;"><span>⚠️</span> ${data.error}</div>`;
                    document.getElementById('activities-list-container').style.display = 'block';
                }
            })
            .catch(err => {
                console.error("Failed to fetch activities", err);
                document.getElementById('act-loading-spinner').style.display = 'none';
                document.getElementById('activities-list-container').innerHTML = 
                    `<div class="alert alert-danger" style="margin-top:0;"><span>⚠️</span> Connection error. Please try again.</div>`;
                document.getElementById('activities-list-container').style.display = 'block';
            });
        }
        
        function closeActivitiesModal() {
            document.getElementById('activities-modal').classList.remove('active');
        }
        
        function renderActivitiesList(activities) {
            const container = document.getElementById('activities-list-container');
            container.style.display = 'block';
            
            if (activities.length === 0) {
                container.innerHTML = `<div style="text-align: center; padding: 2rem; color: var(--text-muted);">
                    <span style="font-size: 2rem;">🎭</span>
                    <p style="margin-top: 0.5rem; font-size: 0.9rem;">No activities are open for this event.</p>
                </div>`;
                return;
            }
            
            activities.forEach(act => {
                const actDiv = document.createElement('div');
                actDiv.className = 'glass-panel';
                actDiv.style.padding = '1.25rem';
                actDiv.style.marginBottom = '1rem';
                actDiv.style.background = 'rgba(255, 255, 255, 0.02)';
                actDiv.style.borderColor = 'var(--border-color)';
                
                let registerSectionHtml = '';
                
                if (act.is_registered) {
                    const rd = act.registration_details;
                    let badgeClass = 'badge-pending';
                    if (rd.status === 'approved') badgeClass = 'badge-approved';
                    if (rd.status === 'rejected') badgeClass = 'badge-rejected';
                    
                    let teamDetails = '';
                    if (act.activity_type !== 'solo') {
                        teamDetails = `<div style="font-size: 0.85rem; color: var(--text-secondary); margin-top: 0.4rem;">
                            <strong>Team Name:</strong> ${rd.team_name} (${rd.is_leader ? 'Leader' : 'Member'})
                        </div>`;
                        
                        if (rd.team_members && rd.team_members.length > 0) {
                            let membersLi = rd.team_members.map(m => 
                                `<li style="margin-bottom: 0.2rem; color: var(--text-secondary); font-size: 0.8rem; display: flex; align-items: center; gap: 0.4rem;">
                                    👤 <strong>${m.name}</strong> (Roll: ${m.roll_no}) ${m.is_leader ? '<span class="badge badge-approved" style="font-size: 0.65rem; padding: 0.1rem 0.3rem;">Leader</span>' : ''}
                                </li>`
                            ).join('');
                            teamDetails += `<div style="margin-top: 0.5rem; background: rgba(0,0,0,0.15); padding: 0.75rem; border-radius: 6px; border: 1px solid var(--border-color);">
                                <div style="font-size: 0.8rem; font-weight: bold; margin-bottom: 0.4rem; color: var(--text-primary);">Team Members:</div>
                                <ul style="list-style: none; padding-left: 0; margin: 0; display: flex; flex-direction: column; gap: 0.25rem;">${membersLi}</ul>
                            </div>`;
                        }
                    }
                    
                    registerSectionHtml = `
                        <div style="display: flex; justify-content: space-between; align-items: center; border-top: 1px solid var(--border-color); padding-top: 0.75rem; margin-top: 0.75rem; flex-wrap: wrap; gap: 0.5rem;">
                            <span class="badge ${badgeClass}">${rd.status.toUpperCase()}</span>
                            <span style="font-size: 0.85rem; color: var(--text-muted); font-weight: 600;">Already Registered</span>
                        </div>
                        ${teamDetails}
                        ${rd.track_link ? `<div style="font-size: 0.8rem; color: var(--text-secondary); margin-top: 0.5rem; word-break: break-all;">🎵 <strong>Music Track:</strong> <a href="${rd.track_link}" target="_blank" style="color: var(--primary); text-decoration: underline;">Link</a></div>` : ''}
                    `;
                } else {
                    // Form fields dynamically generated
                    let formFields = '';
                    if (act.activity_type !== 'solo') {
                        formFields += `
                            <div class="form-group" style="margin-top: 1rem;">
                                <label class="form-label" style="font-size: 0.8rem;">Team Name</label>
                                <input type="text" class="form-control team-name-input" placeholder="Enter team name" style="height: 38px; font-size: 0.9rem;" required>
                            </div>
                            <div class="form-group">
                                <label class="form-label" style="font-size: 0.8rem;">Other Team Members</label>
                                <div class="team-members-container" style="display: flex; flex-direction: column; gap: 0.5rem;">
                                    <!-- Dynamic members inputs -->
                                </div>
                                <button type="button" class="btn btn-secondary" onclick="addMemberInput(this, '${act.activity_type}')" style="padding: 0.35rem 0.75rem; font-size: 0.8rem; margin-top: 0.5rem; width: auto; font-weight: 700;">
                                    ➕ Add Partner / Member
                                </button>
                            </div>
                        `;
                    }
                    
                    formFields += `
                        <div class="form-group" style="margin-top: 1rem;">
                            <label class="form-label" style="font-size: 0.8rem;">Audio/Music Track Link (Optional)</label>
                            <input type="url" class="form-control track-link-input" placeholder="Google Drive or YouTube link" style="height: 38px; font-size: 0.9rem;">
                        </div>
                    `;
                    
                    registerSectionHtml = `
                        <div class="act-reg-form" style="display: none; border-top: 1px solid var(--border-color); padding-top: 0.75rem; margin-top: 0.75rem;">
                            ${formFields}
                            <div style="display: flex; gap: 0.5rem; justify-content: flex-end; margin-top: 1.25rem;">
                                <button type="button" class="btn btn-secondary" onclick="toggleActForm(this, false)" style="padding: 0.4rem 1rem; font-size: 0.85rem;">Cancel</button>
                                <button type="button" class="btn btn-primary" onclick="submitActRegistration(this, ${act.id})" style="padding: 0.4rem 1rem; font-size: 0.85rem;">Submit</button>
                            </div>
                        </div>
                        <button class="btn btn-primary btn-act-toggle" onclick="toggleActForm(this, true)" style="margin-top: 0.75rem; padding: 0.4rem 1rem; font-size: 0.85rem; width: auto; display: block; margin-left: auto;">
                            Register for Activity
                        </button>
                    `;
                }
                
                actDiv.innerHTML = `
                    <div style="display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem;">
                        <div style="width: 100%;">
                            <h5 style="margin: 0; font-size: 1.05rem; font-weight: bold; color: var(--text-primary);">${act.title}</h5>
                            <span class="badge badge-approved" style="font-size: 0.65rem; text-transform: uppercase; display: inline-block; margin-top: 0.25rem; font-weight: bold; background: rgba(59, 130, 246, 0.15); color: #60a5fa; border: 1px solid rgba(59, 130, 246, 0.3);">
                                ⚡ ${act.activity_type}
                            </span>
                            ${act.description ? `<p style="margin: 0.4rem 0 0 0; font-size: 0.85rem; color: var(--text-secondary); line-height: 1.4;">${act.description}</p>` : ''}
                        </div>
                    </div>
                    ${registerSectionHtml}
                `;
                
                container.appendChild(actDiv);
            });
        }
        
        function toggleActForm(button, show) {
            const container = button.closest('.glass-panel');
            const form = container.querySelector('.act-reg-form');
            const toggleBtn = container.querySelector('.btn-act-toggle');
            if (show) {
                form.style.display = 'block';
                toggleBtn.style.display = 'none';
                // If group or duet, ensure we populate initial member inputs
                const membersContainer = form.querySelector('.team-members-container');
                if (membersContainer && membersContainer.children.length === 0) {
                    // Check if it is duet
                    const headerTag = container.querySelector('.badge');
                    const isDuet = headerTag && headerTag.textContent.includes('duet');
                    addMemberInput(form.querySelector('button[onclick*="addMemberInput"]'), isDuet ? 'duet' : 'group');
                }
            } else {
                form.style.display = 'none';
                toggleBtn.style.display = 'block';
            }
        }
        
        function addMemberInput(addButton, type) {
            const container = addButton.previousElementSibling;
            
            // Limit duet to 1 member
            if (type === 'duet' && container.children.length >= 1) {
                alert("Duet activities allow exactly one partner.");
                return;
            }
            
            const div = document.createElement('div');
            div.style.display = 'grid';
            div.style.gridTemplateColumns = '1fr 1fr auto';
            div.style.gap = '0.5rem';
            div.style.alignItems = 'center';
            div.style.marginBottom = '0.25rem';
            div.className = 'team-member-row';
            
            div.innerHTML = `
                <input type="text" class="form-control member-name" placeholder="Member Name" style="height: 34px; font-size: 0.85rem;" required>
                <input type="text" class="form-control member-roll" placeholder="Roll Number" style="height: 34px; font-size: 0.85rem;" required>
                <button type="button" class="btn btn-danger" onclick="this.parentElement.remove()" style="padding: 0; font-size: 0.8rem; border-radius: var(--radius-sm); height: 34px; width: 34px; display: flex; align-items: center; justify-content: center;">❌</button>
            `;
            
            container.appendChild(div);
        }
        
        function submitActRegistration(submitButton, actId) {
            const form = submitButton.closest('.act-reg-form');
            const trackLinkInput = form.querySelector('.track-link-input');
            const teamNameInput = form.querySelector('.team-name-input');
            
            const track_link = trackLinkInput ? trackLinkInput.value.trim() : '';
            const team_name = teamNameInput ? teamNameInput.value.trim() : '';
            
            const memberRows = form.querySelectorAll('.team-member-row');
            const members = [];
            
            let hasIncompleteMember = false;
            memberRows.forEach(row => {
                const name = row.querySelector('.member-name').value.trim();
                const roll_no = row.querySelector('.member-roll').value.trim();
                if (!name || !roll_no) {
                    hasIncompleteMember = true;
                } else {
                    members.push({ name, roll_no });
                }
            });
            
            if (hasIncompleteMember) {
                alert("Please fill in all team member details or remove incomplete rows.");
                return;
            }
            
            if (teamNameInput && !team_name) {
                alert("Team Name is required.");
                return;
            }
            
            // Disable submit button and show loading status
            submitButton.disabled = true;
            submitButton.textContent = 'Submitting...';
            
            fetch('activity_handler.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'register_activity',
                    activity_id: actId,
                    registration_id: currentRegId,
                    track_link: track_link,
                    team_name: team_name,
                    members: members
                })
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert("Activity registration submitted successfully! Awaiting approval.");
                    closeActivitiesModal();
                    // Reopen modal to show updated status
                    openActivitiesModal(currentEventId, currentRegId, document.getElementById('act-modal-event-title').textContent);
                } else {
                    alert("Registration failed: " + data.error);
                    submitButton.disabled = false;
                    submitButton.textContent = 'Submit';
                }
            })
            .catch(err => {
                console.error("Error submitting activity registration", err);
                alert("Connection error. Please try again.");
                submitButton.disabled = false;
                submitButton.textContent = 'Submit';
            });
        }

        // Modal click-off closing behaviour
        window.onclick = function(event) {
            let regModal = document.getElementById('register-modal');
            let tktModal = document.getElementById('ticket-modal');
            let actModal = document.getElementById('activities-modal');
            if (event.target === regModal) {
                closeRegisterModal();
            }
            if (event.target === tktModal) {
                closeTicketModal();
            }
            if (event.target === actModal) {
                closeActivitiesModal();
            }
        }

        // Client-side search and tag filtering
        let activeTag = 'all';
        
        function filterTag(tag, element) {
            document.querySelectorAll('.filter-tag').forEach(btn => {
                btn.classList.remove('active');
            });
            element.classList.add('active');
            activeTag = tag;
            applyFilters();
        }
        
        function filterEvents() {
            applyFilters();
        }
        
        function applyFilters() {
            const query = document.getElementById('event-search').value.toLowerCase().trim();
            const cards = document.querySelectorAll('.event-item-card');
            
            cards.forEach(card => {
                const title = card.getAttribute('data-title') || '';
                const location = card.getAttribute('data-location') || '';
                const price = parseFloat(card.getAttribute('data-price') || '0');
                const registered = card.getAttribute('data-registered') === 'true';
                
                let matchesQuery = title.includes(query) || location.includes(query);
                let matchesTag = false;
                
                if (activeTag === 'all') {
                    matchesTag = true;
                } else if (activeTag === 'free') {
                    matchesTag = (price === 0);
                } else if (activeTag === 'paid') {
                    matchesTag = (price > 0);
                } else if (activeTag === 'registered') {
                    matchesTag = registered;
                }
                
                if (matchesQuery && matchesTag) {
                    card.style.display = 'flex';
                    card.style.animation = 'fadeIn 0.3s ease';
                } else {
                    card.style.display = 'none';
                }
            });
        }

        // Time of day greeting
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
                greetingEl.textContent = greeting + ', <?php echo htmlspecialchars($_SESSION['user_name']); ?>!';
            }
        });
    </script>
</body>
</html>

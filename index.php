<?php
require_once 'db_connect.php';
require_once 'otp_handler.php';

// Fetch all events
try {
    $events_stmt = $conn->query("SELECT * FROM events WHERE is_active = 1 ORDER BY event_date ASC LIMIT 3");
    $events = $events_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $events = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nexus | Campus Event Management & Ticketing</title>
    <link rel="stylesheet" href="css/style.css?v=1.6">
    <script src="js/theme.js?v=1.6"></script>
</head>
<body>
    <!-- Top glass header -->
    <header>
        <div class="nav-container">
            <a href="index.php" class="logo-container">
                <img src="images/nexus_logo.png" alt="Nexus Logo" class="logo-img">
                <span class="logo-text">Nexus</span>
            </a>
            <ul class="nav-links">
                <li><a href="index.php" class="active">Home</a></li>
                <?php if (isset($_SESSION['user_id'])): ?>
                    <?php if ($_SESSION['user_role'] === 'admin'): ?>
                        <li><a href="admin_dashboard.php">Admin Panel</a></li>
                    <?php else: ?>
                        <li><a href="dashboard.php">Dashboard</a></li>
                    <?php endif; ?>
                    <li><a href="logout.php" class="nav-btn">Logout</a></li>
                <?php else: ?>
                    <li><a href="login.php">Login</a></li>
                    <li><a href="register.php" class="nav-btn btn-primary">Register</a></li>
                <?php endif; ?>
                <li><button class="theme-toggle-btn" aria-label="Toggle Theme">🌙</button></li>
            </ul>
        </div>
    </header>

    <!-- Hero Section -->
    <section class="hero">
        <div class="badge-glow">Next-gen Event Verification System</div>
        <h1>Elevate Your Campus Event Experience</h1>
        <p>A secure, end-to-end ticketing and registration platform with simulated OTP authentication, admin payment validation, and entry/exit QR attendance tracking.</p>
        
        <div style="display: flex; gap: 1rem; justify-content: center; margin-top: 1rem;">
            <?php if (isset($_SESSION['user_id'])): ?>
                <a href="<?php echo $_SESSION['user_role'] === 'admin' ? 'admin_dashboard.php' : 'dashboard.php'; ?>" class="btn btn-primary" style="padding: 0.9rem 2rem; font-size: 1.05rem;">Go to Dashboard</a>
            <?php else: ?>
                <a href="register.php" class="btn btn-primary" style="padding: 0.9rem 2rem; font-size: 1.05rem;">Create Account</a>
                <a href="login.php" class="btn btn-secondary" style="padding: 0.9rem 2rem; font-size: 1.05rem;">Sign In</a>
            <?php endif; ?>
        </div>

        <!-- Stats Banner -->
        <div class="stats-banner" style="width: 100%; max-width: 900px;">
            <div class="stat-item">
                <div class="stat-item-num">100%</div>
                <div class="stat-item-label">Secure QR Access</div>
            </div>
            <div class="stat-item">
                <div class="stat-item-num">2FA</div>
                <div class="stat-item-label">Simulated OTP Verif.</div>
            </div>
            <div class="stat-item">
                <div class="stat-item-num">1-Click</div>
                <div class="stat-item-label">Admin Approval Flow</div>
            </div>
        </div>
    </section>



    <!-- Feature Description Panels -->
    <section style="background: rgba(255,255,255,0.01); border-top: 1px solid var(--border-color); border-bottom: 1px solid var(--border-color); padding: 5rem 0;">
        <div class="container">
            <h2 style="text-align: center; margin-bottom: 3.5rem; font-size: 2.25rem;">Engineered for Secure Campus Management</h2>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 2rem;">
                <div class="glass-panel" style="margin-bottom: 0; padding: 2rem;">
                    <div style="font-size: 2.5rem; margin-bottom: 1rem;">🔐</div>
                    <h3 style="font-size: 1.25rem; margin-bottom: 0.5rem;">Two-Factor Accounts</h3>
                    <p style="color: var(--text-secondary); font-size: 0.9rem;">Sign-up and log in securely with random 6-digit OTP verification codes displayed as interactive toasts for convenient local testing.</p>
                </div>
                
                <div class="glass-panel" style="margin-bottom: 0; padding: 2rem;">
                    <div style="font-size: 2.5rem; margin-bottom: 1rem;">🧾</div>
                    <h3 style="font-size: 1.25rem; margin-bottom: 0.5rem;">Payment Verification</h3>
                    <p style="color: var(--text-secondary); font-size: 0.9rem;">Students upload their payment receipt screenshot. Administrators examine files in an interactive image lightbox before granting approval.</p>
                </div>
                
                <div class="glass-panel" style="margin-bottom: 0; padding: 2rem;">
                    <div style="font-size: 2.5rem; margin-bottom: 1rem;">📷</div>
                    <h3 style="font-size: 1.25rem; margin-bottom: 0.5rem;">Dual QR Access Scan</h3>
                    <p style="color: var(--text-secondary); font-size: 0.9rem;">Webcam scanner records attendee timestamps. The first scan registers Entry, the second registers Exit, and the third fails as deactivated.</p>
                </div>
            </div>
        </div>
    </section>

    <footer style="padding: 2.5rem 0; text-align: center; font-size: 0.85rem; color: var(--text-muted); border-top: 1px solid rgba(255,255,255,0.02);">
        <div class="container">
            <p>&copy; 2026 Nexus Campus Portal.</p>
            <p>&copy; Developed By Rahul Pramanick</p>
        </div>
    </footer>
    
    <!-- Render simulated mail server window for OTP checks during active registration/login redirects -->
    <?php renderOTPToast(); ?>
</body>
</html>

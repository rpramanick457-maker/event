<?php
session_start();
ob_start();
require_once 'db_connect.php';
require_once 'otp_handler.php';
require_once 'security.php';

$error = '';
$success = '';
$step = isset($_GET['step']) ? $_GET['step'] : 'signup';

// If user is already logged in, redirect to dashboard
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['user_role'] === 'admin') {
        header('Location: admin_dashboard.php');
    } else {
        header('Location: dashboard.php');
    }
    exit();
}

// Handle Form Submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    validateCsrfToken(); // 🔐 CSRF check — blocks forged requests
    if (isset($_POST['action']) && $_POST['action'] === 'signup') {
        $name = trim($_POST['name']);
        $email = trim($_POST['email']);
        $password = $_POST['password'];
        $confirm_password = $_POST['confirm_password'];
        
        if (empty($name) || empty($email) || empty($password) || empty($confirm_password)) {
            $error = 'All fields are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (strlen($password) < 6) {
            $error = 'Password must be at least 6 characters long.';
        } elseif ($password !== $confirm_password) {
            $error = 'Passwords do not match.';
        } else {
            // Check if email already exists
            $stmt = $conn->prepare("SELECT id, is_verified FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && $user['is_verified'] == 1) {
                $error = 'Email is already registered. Please login.';
            } else {
                // Generate OTP and store in session
                $otp = generateOTP();
                $_SESSION['pending_reg'] = [
                    'name'     => $name,
                    'email'    => $email,
                    'password' => password_hash($password, PASSWORD_DEFAULT),
                    'otp'      => $otp,
                    'expiry'   => time() + 600
                ];

                // Send OTP email in background
                triggerAsyncOTP($email, $otp, 'Registration');

                // Switch to OTP step directly (no redirect)
                $step = 'otp';
            }
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'verify_reg') {
        $step = 'otp'; // stay on OTP page unless we succeed
        $otp_input = trim($_POST['otp']);
        
        if (empty($otp_input)) {
            $error = 'Please enter the verification code.';
        } elseif (!isset($_SESSION['pending_reg'])) {
            $error = 'Session expired. Please sign up again.';
            $step = 'signup';
        } else {
            $pending = $_SESSION['pending_reg'];
            
            if (time() > $pending['expiry']) {
                $error = 'OTP has expired. Please sign up again.';
                unset($_SESSION['pending_reg']);
                $step = 'signup';
            } elseif ($otp_input !== $pending['otp']) {
                $error = 'Invalid verification code. Please try again.';
            } else {
                // OTP is correct! Create user
                try {
                    // Delete any existing unverified record for this email
                    $delete_stmt = $conn->prepare("DELETE FROM users WHERE email = ? AND is_verified = 0");
                    $delete_stmt->execute([$pending['email']]);
                    
                    // Insert verified user
                    $insert_stmt = $conn->prepare("INSERT INTO users (name, email, password, is_verified, role) VALUES (?, ?, ?, 1, 'student')");
                    $insert_stmt->execute([
                        $pending['name'],
                        $pending['email'],
                        $pending['password']
                    ]);
                    
                    // Clean up session registration data
                    unset($_SESSION['pending_reg']);
                    unset($_SESSION['otp_toast']);
                    
                    $_SESSION['success_message'] = 'Account successfully created! Please login.';
                    header('Location: login.php');
                    exit();
                } catch (PDOException $e) {
                    $error = 'Database error: ' . $e->getMessage();
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register | Nexus</title>
    <meta name="description" content="Create your Nexus account to register for campus events.">
    <link rel="stylesheet" href="css/style.css?v=1.7">
    <link rel="stylesheet" href="css/auth-anim.css?v=1.0">
    <script src="js/theme.js?v=1.6"></script>
</head>
<body class="auth-page">

    <!-- Animated background orbs -->
    <div class="auth-bg">
        <div class="auth-orb auth-orb-1"></div>
        <div class="auth-orb auth-orb-2"></div>
        <div class="auth-orb auth-orb-3"></div>
    </div>

    <!-- Top glass header -->
    <header class="auth-header">
        <div class="nav-container">
            <a href="index.php" class="logo-container">
                <img src="images/nexus_logo.png" alt="Nexus Logo" class="logo-img">
                <span class="logo-text">Nexus</span>
            </a>
            <ul class="nav-links">
                <li><a href="index.php">Home</a></li>
                <li><button class="theme-toggle-btn" aria-label="Toggle Theme">🌙</button></li>
            </ul>
        </div>
    </header>

    <?php if ($step === 'otp'): ?>
    <!-- OTP Verification Step -->
    <div class="auth-otp-container">
        <div class="otp-card">
            <div class="otp-icon-ring">
                <span class="otp-icon">📧</span>
            </div>
            <h2 class="otp-title">Verify Email</h2>
            <p class="otp-subtitle">
                Enter the 6-digit code sent to
                <strong><?php echo htmlspecialchars($_SESSION['pending_reg']['email'] ?? 'your email'); ?></strong>
            </p>

            <?php if (!empty($error)): ?>
                <div class="auth-alert auth-alert-danger">
                    <span class="alert-icon">⚠️</span>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <form action="register.php?step=otp" method="POST" class="otp-form">
                <?php csrfField(); ?>                <input type="hidden" name="action" value="verify_reg">                <div class="otp-inputs-row">
                    <?php for ($i = 1; $i <= 6; $i++): ?>
                        <input type="text" class="otp-digit" maxlength="1" inputmode="numeric" pattern="[0-9]" id="otp-digit-<?php echo $i; ?>" autocomplete="off">
                    <?php endfor; ?>
                    <input type="hidden" name="otp" id="otp-hidden">
                </div>
                <button type="submit" name="action_verify" value="1" id="otp-submit-btn" class="btn-auth-primary">
                    <span class="btn-text">Verify &amp; Create Account</span>
                    <span class="btn-icon">→</span>
                </button>
            </form>

            <div class="otp-footer">
                <a href="register.php" class="otp-back-link">← Restart Registration</a>
            </div>
        </div>
    </div>

    <?php else: ?>
    <!-- Signup form — slide to register panel on login.php -->
    <div class="auth-container register-mode" id="authContainer">

        <!-- Sign In Form (Left Panel — pre-filled for navigation) -->
        <div class="auth-form-panel auth-form-left">
            <div class="auth-form-inner">
                <div class="auth-brand-icon">🚀</div>
                <h2 class="auth-form-title">Welcome Back</h2>
                <p class="auth-form-sub">Sign in to access your dashboard</p>

                <form action="login.php" method="POST" class="auth-form">
                    <?php csrfField(); ?>
                    <input type="hidden" name="action" value="login">
                    <div class="auth-field">
                        <label for="login-email" class="auth-label">Email Address</label>
                        <div class="auth-input-wrap">
                            <span class="auth-input-icon">✉️</span>
                            <input type="email" id="login-email" name="email" class="auth-input"
                                placeholder="your.name@student.edu" required>
                        </div>
                    </div>
                    <div class="auth-field">
                        <label for="login-password" class="auth-label">Password</label>
                        <div class="auth-input-wrap">
                            <span class="auth-input-icon">🔒</span>
                            <input type="password" id="login-password" name="password" class="auth-input"
                                placeholder="••••••••" required>
                            <button type="button" class="auth-eye-btn" onclick="togglePass('login-password', this)" tabindex="-1">👁️</button>
                        </div>
                    </div>
                    <button type="submit" name="action_login" value="1" class="btn-auth-primary">
                        <span class="btn-text">Sign In</span>
                        <span class="btn-icon">→</span>
                    </button>
                </form>

                <p class="auth-switch-text">
                    Don't have an account?
                    <button class="auth-switch-btn" onclick="switchToRegister()">Register Here</button>
                </p>
            </div>
        </div>

        <!-- Register Form (Right Panel) -->
        <div class="auth-form-panel auth-form-right">
            <div class="auth-form-inner">
                <div class="auth-brand-icon">✨</div>
                <h2 class="auth-form-title">Create Account</h2>
                <p class="auth-form-sub">Join Nexus and discover campus events</p>

                <?php if (!empty($error)): ?>
                    <div class="auth-alert auth-alert-danger">
                        <span class="alert-icon">⚠️</span>
                        <span><?php echo htmlspecialchars($error); ?></span>
                    </div>
                <?php endif; ?>

                <form action="register.php" method="POST" class="auth-form">
                    <?php csrfField(); ?>
                    <input type="hidden" name="action" value="signup">
                    <div class="auth-field">
                        <label for="reg-name" class="auth-label">Full Name</label>
                        <div class="auth-input-wrap">
                            <span class="auth-input-icon">👤</span>
                            <input type="text" id="reg-name" name="name" class="auth-input"
                                placeholder="John Doe" required
                                value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>">
                        </div>
                    </div>
                    <div class="auth-field">
                        <label for="reg-email" class="auth-label">Student Email</label>
                        <div class="auth-input-wrap">
                            <span class="auth-input-icon">✉️</span>
                            <input type="email" id="reg-email" name="email" class="auth-input"
                                placeholder="john.doe@student.edu" required
                                value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
                        </div>
                    </div>
                    <div class="auth-field">
                        <label for="reg-password" class="auth-label">Password</label>
                        <div class="auth-input-wrap">
                            <span class="auth-input-icon">🔒</span>
                            <input type="password" id="reg-password" name="password" class="auth-input"
                                placeholder="Min. 6 characters" required>
                            <button type="button" class="auth-eye-btn" onclick="togglePass('reg-password', this)" tabindex="-1">👁️</button>
                        </div>
                    </div>
                    <div class="auth-field">
                        <label for="reg-confirm" class="auth-label">Confirm Password</label>
                        <div class="auth-input-wrap">
                            <span class="auth-input-icon">🔐</span>
                            <input type="password" id="reg-confirm" name="confirm_password" class="auth-input"
                                placeholder="••••••••" required>
                        </div>
                    </div>
                    <button type="submit" name="action_signup" value="1" class="btn-auth-primary">
                        <span class="btn-text">Create Account</span>
                        <span class="btn-icon">→</span>
                    </button>
                </form>

                <p class="auth-switch-text">
                    Already have an account?
                    <button class="auth-switch-btn" onclick="switchToLogin()">Login Here</button>
                </p>
            </div>
        </div>

        <!-- Sliding Overlay Panel -->
        <div class="auth-overlay-container" id="overlayContainer">
            <div class="auth-overlay">

                <div class="auth-overlay-panel auth-overlay-left">
                    <div class="overlay-content">
                        <div class="overlay-logo">🎓</div>
                        <h3 class="overlay-title">Welcome Back!</h3>
                        <p class="overlay-text">Already have an account? Sign in to access your events and tickets.</p>
                        <button class="btn-overlay" onclick="switchToLogin()">Sign In</button>
                    </div>
                    <div class="overlay-decoration">
                        <div class="overlay-circle c1"></div>
                        <div class="overlay-circle c2"></div>
                        <div class="overlay-circle c3"></div>
                    </div>
                </div>

                <div class="auth-overlay-panel auth-overlay-right">
                    <div class="overlay-content">
                        <div class="overlay-logo">🌟</div>
                        <h3 class="overlay-title">New Here?</h3>
                        <p class="overlay-text">Join thousands of students. Register now to discover and book amazing campus events.</p>
                        <button class="btn-overlay" onclick="switchToRegister()">Register Now</button>
                    </div>
                    <div class="overlay-decoration">
                        <div class="overlay-circle c1"></div>
                        <div class="overlay-circle c2"></div>
                        <div class="overlay-circle c3"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Render simulated mail server window for developers/testers -->
    <?php renderOTPToast(); ?>

    <script>
        const container = document.getElementById('authContainer');

        function switchToRegister() {
            if (!container) return;
            container.classList.add('register-mode');
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function switchToLogin() {
            if (!container) return;
            container.classList.remove('register-mode');
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function togglePass(inputId, btn) {
            const input = document.getElementById(inputId);
            if (input.type === 'password') {
                input.type = 'text';
                btn.textContent = '🙈';
            } else {
                input.type = 'password';
                btn.textContent = '👁️';
            }
        }

        // OTP digit boxes
        const otpDigits = document.querySelectorAll('.otp-digit');
        if (otpDigits.length > 0) {
            otpDigits.forEach((input, idx) => {
                input.addEventListener('input', (e) => {
                    const val = e.target.value.replace(/\D/g, '');
                    e.target.value = val.slice(-1);
                    if (val && idx < otpDigits.length - 1) otpDigits[idx + 1].focus();
                    assembleOTP();
                });
                input.addEventListener('keydown', (e) => {
                    if (e.key === 'Backspace' && !e.target.value && idx > 0) {
                        otpDigits[idx - 1].focus();
                        otpDigits[idx - 1].value = '';
                        assembleOTP();
                    }
                });
                input.addEventListener('paste', (e) => {
                    e.preventDefault();
                    const pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '');
                    [...pasted.slice(0, 6)].forEach((char, i) => { if (otpDigits[i]) otpDigits[i].value = char; });
                    const lastFilled = Math.min(pasted.length, 6) - 1;
                    if (otpDigits[lastFilled]) otpDigits[lastFilled].focus();
                    assembleOTP();
                });
            });
            otpDigits[0].focus();
        }

        function assembleOTP() {
            const hidden = document.getElementById('otp-hidden');
            if (hidden) hidden.value = [...otpDigits].map(i => i.value).join('');
        }

        // Submit loading state
        document.querySelectorAll('.auth-form, .otp-form').forEach(form => {
            form.addEventListener('submit', function() {
                const btn = this.querySelector('[type="submit"]');
                if (btn) {
                    btn.disabled = true;
                    const text = btn.querySelector('.btn-text');
                    if (text) text.textContent = 'Please wait...';
                    btn.style.opacity = '0.8';
                }
            });
        });

        // Input focus animation
        document.querySelectorAll('.auth-input').forEach(input => {
            input.addEventListener('focus', () => input.closest('.auth-input-wrap')?.classList.add('focused'));
            input.addEventListener('blur', () => input.closest('.auth-input-wrap')?.classList.remove('focused'));
        });
    </script>
</body>
</html>

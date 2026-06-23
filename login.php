<?php
session_start();
ob_start();
require_once 'db_connect.php';
require_once 'otp_handler.php';
require_once 'security.php';

$error = '';
$success = '';
$step = isset($_GET['step']) ? $_GET['step'] : 'login';

// Check if there is a success message from registration
if (isset($_SESSION['success_message'])) {
    $success = $_SESSION['success_message'];
    unset($_SESSION['success_message']);
}

// If user is already logged in, redirect to appropriate dashboard
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
    if (isset($_POST['action']) && $_POST['action'] === 'login') {
        $email = trim($_POST['email']);
        $password = $_POST['password'];
        
        if (empty($email) || empty($password)) {
            $error = 'Please enter your email and password.';
        } else {
            // Fetch user from DB
            $stmt = $conn->prepare("SELECT * FROM users WHERE email = ?");
            $stmt->execute([$email]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user && password_verify($password, $user['password'])) {
                if ($user['is_verified'] == 0) {
                    $error = 'Your account email is unverified.';
                } else {
                    // Credentials correct! Generate OTP
                    $otp = generateOTP();
                    $expiry = date('Y-m-d H:i:s', time() + 600); // 10 mins
                    
                    // Hash the OTP before saving to database
                    $hashed_otp = password_hash($otp, PASSWORD_DEFAULT);
                    
                    // Save hashed OTP to DB
                    $update = $conn->prepare("UPDATE users SET otp_code = ?, otp_expiry = ? WHERE id = ?");
                    $update->execute([$hashed_otp, $expiry, $user['id']]);
                    
                    // Set pending user in session
                    $_SESSION['pending_login_user_id'] = $user['id'];
                    
                    // Trigger async background email send
                    triggerAsyncOTP($user['email'], $otp, 'Login');
                    
                    // Show OTP step directly (no redirect)
                    $step = 'otp';
                }
            } else {
                $error = 'Invalid email or password.';
            }
        }
    } elseif (isset($_POST['action']) && $_POST['action'] === 'verify_login') {
        $step = 'otp'; // stay on OTP page unless login succeeds
        $otp_input = trim($_POST['otp']);
        
        if (empty($otp_input)) {
            $error = 'Please enter the verification code.';
        } elseif (!isset($_SESSION['pending_login_user_id'])) {
            $error = 'Session expired. Please log in again.';
            $step = 'login';
        } else {
            $user_id = $_SESSION['pending_login_user_id'];
            
            $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
            $stmt->execute([$user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($user) {
                $current_time = date('Y-m-d H:i:s');
                
                if ($user['otp_code'] === null || $user['otp_expiry'] === null) {
                    $error = 'No active OTP session. Please log in again.';
                    $step = 'login';
                } elseif ($current_time > $user['otp_expiry']) {
                    $error = 'OTP has expired. Please log in again.';
                    $step = 'login';
                } elseif (!password_verify($otp_input, $user['otp_code'])) {
                    $error = 'Invalid verification code. Please try again.';
                } else {
                    // Login Success!
                    $clear = $conn->prepare("UPDATE users SET otp_code = NULL, otp_expiry = NULL WHERE id = ?");
                    $clear->execute([$user['id']]);
                    
                    $_SESSION['user_id']    = $user['id'];
                    $_SESSION['user_name']  = $user['name'];
                    $_SESSION['user_email'] = $user['email'];
                    $_SESSION['user_role']  = $user['role'];
                    
                    unset($_SESSION['pending_login_user_id']);
                    
                    if ($user['role'] === 'admin') {
                        header('Location: admin_dashboard.php');
                    } else {
                        header('Location: dashboard.php');
                    }
                    exit();
                }
            } else {
                $error = 'User not found.';
                $step = 'login';
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
    <title>Login | Nexus</title>
    <meta name="description" content="Sign in to Nexus to access your dashboard and event tickets.">
    <link rel="stylesheet" href="css/style.css?v=1.7">
    <link rel="stylesheet" href="css/auth-anim.css?v=1.0">
    <script src="js/theme.js?v=1.6"></script>
</head>
<body class="auth-page">

    <!-- Animated background particles -->
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
                <span class="otp-icon">🔐</span>
            </div>
            <h2 class="otp-title">Verify Identity</h2>
            <p class="otp-subtitle">Enter the 6-digit code sent to your email</p>

            <?php if (!empty($error)): ?>
                <div class="auth-alert auth-alert-danger">
                    <span class="alert-icon">⚠️</span>
                    <span><?php echo htmlspecialchars($error); ?></span>
                </div>
            <?php endif; ?>

            <form action="login.php?step=otp" method="POST" class="otp-form">
                <?php csrfField(); ?>
                <input type="hidden" name="action" value="verify_login">
                <div class="otp-inputs-row">
                    <?php for ($i = 1; $i <= 6; $i++): ?>
                        <input type="text" class="otp-digit" maxlength="1" inputmode="numeric" pattern="[0-9]" id="otp-digit-<?php echo $i; ?>" autocomplete="off">
                    <?php endfor; ?>
                    <input type="hidden" name="otp" id="otp-hidden">
                </div>
                <button type="submit" name="action_verify_login" value="1" id="otp-submit-btn" class="btn-auth-primary">
                    <span class="btn-text">Verify & Sign In</span>
                    <span class="btn-icon">→</span>
                </button>
            </form>

            <div class="otp-footer">
                <a href="login.php" class="otp-back-link">← Back to Login</a>
            </div>
        </div>
    </div>

    <?php else: ?>
    <!-- Login / Register Sliding Panel -->
    <div class="auth-container" id="authContainer">

        <!-- Sign In Form (Left Panel) -->
        <div class="auth-form-panel auth-form-left">
            <div class="auth-form-inner">
                <div class="auth-brand-icon"></div>
                <h2 class="auth-form-title">Welcome Back</h2>
                <p class="auth-form-sub">Sign in to access your dashboard</p>

                <?php if (!empty($success)): ?>
                    <div class="auth-alert auth-alert-success">
                        <span class="alert-icon">✅</span>
                        <span><?php echo htmlspecialchars($success); ?></span>
                    </div>
                <?php endif; ?>

                <?php if (!empty($error)): ?>
                    <div class="auth-alert auth-alert-danger">
                        <span class="alert-icon">⚠️</span>
                        <span><?php echo htmlspecialchars($error); ?></span>
                    </div>
                <?php endif; ?>

                <form action="login.php" method="POST" class="auth-form" id="loginForm">
                    <?php csrfField(); ?>
                    <input type="hidden" name="action" value="login">
                    <div class="auth-field">
                        <label for="login-email" class="auth-label">Email Address</label>
                        <div class="auth-input-wrap">
                            <span class="auth-input-icon">✉️</span>
                            <input type="email" id="login-email" name="email" class="auth-input"
                                placeholder="your.name@student.edu" required
                                value="<?php echo isset($_POST['email']) ? htmlspecialchars($_POST['email']) : ''; ?>">
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
                    <button type="submit" name="action_login" value="1" class="btn-auth-primary" id="loginSubmitBtn">
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

                <div id="reg-error-area"></div>

                <form action="register.php" method="POST" class="auth-form" id="registerForm">
                    <?php csrfField(); ?>
                    <input type="hidden" name="action" value="signup">
                    <div class="auth-field">
                        <label for="reg-name" class="auth-label">Full Name</label>
                        <div class="auth-input-wrap">
                            <span class="auth-input-icon">👤</span>
                            <input type="text" id="reg-name" name="name" class="auth-input"
                                placeholder="John Doe" required>
                        </div>
                    </div>
                    <div class="auth-field">
                        <label for="reg-email" class="auth-label">Student Email</label>
                        <div class="auth-input-wrap">
                            <span class="auth-input-icon">✉️</span>
                            <input type="email" id="reg-email" name="email" class="auth-input"
                                placeholder="john.doe@student.edu" required>
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

                <!-- Left overlay (shown when on Register side) -->
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

                <!-- Right overlay (shown when on Login side) -->
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
    </div><!-- end .auth-container -->
    <?php endif; ?>

    <!-- Render simulated mail server window for developers/testers -->
    <?php renderOTPToast(); ?>

    <script>
        // ─── Sliding Panel Logic ───────────────────────────────────────────────
        const container = document.getElementById('authContainer');

        function switchToRegister() {
            if (!container) return;
            container.classList.add('register-mode');
            // Smooth scroll to top on mobile
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        function switchToLogin() {
            if (!container) return;
            container.classList.remove('register-mode');
            window.scrollTo({ top: 0, behavior: 'smooth' });
        }

        // ─── Toggle Password Visibility ────────────────────────────────────────
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

        // ─── OTP Digit Box Logic ───────────────────────────────────────────────
        const otpDigits = document.querySelectorAll('.otp-digit');
        if (otpDigits.length > 0) {
            otpDigits.forEach((input, idx) => {
                input.addEventListener('input', (e) => {
                    const val = e.target.value.replace(/\D/g, '');
                    e.target.value = val.slice(-1);
                    if (val && idx < otpDigits.length - 1) {
                        otpDigits[idx + 1].focus();
                    }
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
                    [...pasted.slice(0, 6)].forEach((char, i) => {
                        if (otpDigits[i]) otpDigits[i].value = char;
                    });
                    const lastFilled = Math.min(pasted.length, 6) - 1;
                    if (otpDigits[lastFilled]) otpDigits[lastFilled].focus();
                    assembleOTP();
                });
            });

            // Focus first digit on load
            otpDigits[0].focus();
        }

        function assembleOTP() {
            const hidden = document.getElementById('otp-hidden');
            if (hidden) {
                hidden.value = [...otpDigits].map(i => i.value).join('');
            }
        }

        // ─── Submit button loading state ───────────────────────────────────────
        document.querySelectorAll('.auth-form, .otp-form').forEach(form => {
            form.addEventListener('submit', function() {
                const btn = this.querySelector('[type="submit"]');
                if (btn) {
                    btn.disabled = true;
                    btn.querySelector('.btn-text').textContent = 'Please wait...';
                    btn.style.opacity = '0.8';
                }
            });
        });

        // ─── Input animation on focus ──────────────────────────────────────────
        document.querySelectorAll('.auth-input').forEach(input => {
            input.addEventListener('focus', () => {
                input.closest('.auth-input-wrap')?.classList.add('focused');
            });
            input.addEventListener('blur', () => {
                input.closest('.auth-input-wrap')?.classList.remove('focused');
            });
        });
    </script>
</body>
</html>

<?php
// helper_scanner.php – Mobile-optimised entry point for event helpers.
// Helpers enter their unique passcode to be redirected to the mobile scanner.
require_once 'db_connect.php';
session_start();

$message = '';
$message_type = 'error';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $helper_key = trim($_POST['helper_key'] ?? '');
    if ($helper_key === '') {
        $message = 'Please enter your passcode.';
    } else {
        try {
            $stmt = $conn->prepare('SELECT name FROM helpers WHERE helper_key = ?');
            $stmt->execute([$helper_key]);
            $helper = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($helper) {
                $_SESSION['helper_key'] = $helper_key;
                $_SESSION['helper_name'] = $helper['name'];
                // Redirect to mobile scanner with helper_key in URL
                header('Location: mobile_scanner.php?helper_key=' . urlencode($helper_key));
                exit();
            } else {
                $message = 'Invalid passcode. Please check with your event administrator.';
            }
        } catch (PDOException $e) {
            $message = 'A database error occurred. Please try again.';
        }
    }
}

// Build the base URL for sharing
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'];
$scanner_url = $protocol . '://' . $host . '/event/helper_scanner.php';
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#130E0A">
    <title>Helper Scanner Login | Nexus</title>
    <link rel="manifest" href="manifest.json">
    <link rel="stylesheet" href="css/style.css?v=2.0">
    <script src="js/theme.js?v=2.0"></script>
    <style>
        /* Force dark theme for mobile scanner pages */
        html, body {
            height: 100%;
            overflow-x: hidden;
        }

        body {
            background: #130E0A;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 1.5rem 1rem;
            position: relative;
            overflow: hidden;
        }

        /* Animated background blobs */
        .bg-blob {
            position: fixed;
            border-radius: 50%;
            filter: blur(80px);
            opacity: 0.15;
            pointer-events: none;
            animation: blob-drift 12s ease-in-out infinite alternate;
        }
        .bg-blob-1 {
            width: 350px; height: 350px;
            background: #FFA852;
            top: -100px; left: -100px;
        }
        .bg-blob-2 {
            width: 300px; height: 300px;
            background: #FF6B35;
            bottom: -80px; right: -80px;
            animation-delay: -6s;
        }
        @keyframes blob-drift {
            0% { transform: translate(0, 0) scale(1); }
            100% { transform: translate(30px, 30px) scale(1.1); }
        }

        /* Login Card */
        .login-card {
            width: 100%;
            max-width: 420px;
            background: rgba(32, 23, 17, 0.75);
            backdrop-filter: blur(32px);
            -webkit-backdrop-filter: blur(32px);
            border: 1px solid rgba(255, 168, 82, 0.2);
            border-radius: 24px;
            padding: 2.5rem 2rem;
            box-shadow:
                0 0 0 1px rgba(255,255,255,0.03),
                0 20px 60px rgba(0,0,0,0.5),
                0 0 80px rgba(255,168,82,0.05);
            position: relative;
            z-index: 1;
        }

        /* Glowing top border line */
        .login-card::before {
            content: '';
            position: absolute;
            top: 0; left: 10%; right: 10%;
            height: 1px;
            background: linear-gradient(90deg, transparent, rgba(255,168,82,0.6), transparent);
            border-radius: 0 0 50% 50%;
        }

        /* Brand logo area */
        .brand-area {
            text-align: center;
            margin-bottom: 2rem;
        }

        .brand-icon {
            width: 72px;
            height: 72px;
            border-radius: 20px;
            background: linear-gradient(135deg, rgba(255,168,82,0.2), rgba(255,107,53,0.1));
            border: 1px solid rgba(255,168,82,0.3);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            margin: 0 auto 1.25rem;
            box-shadow: 0 0 30px rgba(255,168,82,0.2);
            animation: icon-pulse 3s ease-in-out infinite;
        }

        @keyframes icon-pulse {
            0%, 100% { box-shadow: 0 0 20px rgba(255,168,82,0.2); }
            50% { box-shadow: 0 0 40px rgba(255,168,82,0.4); }
        }

        .brand-name {
            font-family: 'Outfit', sans-serif;
            font-size: 1.6rem;
            font-weight: 800;
            color: #FCF8F4;
            letter-spacing: -0.02em;
        }
        .brand-name span {
            color: #FFA852;
        }

        .brand-subtitle {
            font-size: 0.85rem;
            color: #8A7566;
            margin-top: 0.3rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.4rem;
        }

        .brand-subtitle::before, .brand-subtitle::after {
            content: '';
            flex: 1;
            max-width: 40px;
            height: 1px;
            background: rgba(255,255,255,0.1);
        }

        /* Divider text */
        .section-title {
            font-size: 0.8rem;
            font-weight: 700;
            color: #8A7566;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            margin-bottom: 0.5rem;
        }

        /* Passcode input with icon */
        .input-wrapper {
            position: relative;
            margin-bottom: 1.5rem;
        }

        .input-icon {
            position: absolute;
            left: 1rem;
            top: 50%;
            transform: translateY(-50%);
            font-size: 1.1rem;
            pointer-events: none;
            z-index: 1;
        }

        .passcode-input {
            width: 100%;
            background: rgba(44, 32, 24, 0.8);
            border: 1.5px solid rgba(255,255,255,0.08);
            border-radius: 12px;
            padding: 1rem 1rem 1rem 3rem;
            color: #FCF8F4;
            font-family: 'Plus Jakarta Sans', sans-serif;
            font-size: 1rem;
            font-weight: 600;
            letter-spacing: 0.02em;
            transition: all 0.3s ease;
            -webkit-appearance: none;
        }

        .passcode-input::placeholder {
            color: #8A7566;
            font-weight: 400;
            letter-spacing: 0;
        }

        .passcode-input:focus {
            outline: none;
            border-color: rgba(255, 168, 82, 0.6);
            background: rgba(44, 32, 24, 1);
            box-shadow: 0 0 0 3px rgba(255, 168, 82, 0.1), 0 0 20px rgba(255, 168, 82, 0.1);
        }

        /* Toggle passcode visibility */
        .input-toggle-btn {
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: #8A7566;
            cursor: pointer;
            font-size: 1rem;
            padding: 0;
            line-height: 1;
        }

        /* Submit button */
        .submit-btn {
            width: 100%;
            background: linear-gradient(135deg, #FFA852, #FF6B35);
            border: none;
            border-radius: 12px;
            padding: 1rem 1.5rem;
            color: #130E0A;
            font-family: 'Outfit', sans-serif;
            font-size: 1rem;
            font-weight: 800;
            letter-spacing: 0.02em;
            cursor: pointer;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.6rem;
            box-shadow: 0 4px 20px rgba(255, 168, 82, 0.3);
            position: relative;
            overflow: hidden;
        }

        .submit-btn::before {
            content: '';
            position: absolute;
            top: 0; left: -100%;
            width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.15), transparent);
            transition: left 0.5s ease;
        }

        .submit-btn:hover::before {
            left: 100%;
        }

        .submit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(255, 168, 82, 0.5);
        }

        .submit-btn:active {
            transform: translateY(0);
        }

        /* Error message */
        .error-msg {
            display: flex;
            align-items: center;
            gap: 0.6rem;
            background: rgba(248, 113, 113, 0.1);
            border: 1px solid rgba(248, 113, 113, 0.3);
            color: #F87171;
            border-radius: 10px;
            padding: 0.75rem 1rem;
            font-size: 0.88rem;
            font-weight: 500;
            margin-bottom: 1.25rem;
            animation: shake 0.4s ease;
        }

        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-6px); }
            40%, 80% { transform: translateX(6px); }
        }

        /* Info note */
        .info-note {
            background: rgba(255,168,82,0.06);
            border: 1px solid rgba(255,168,82,0.15);
            border-radius: 10px;
            padding: 0.85rem 1rem;
            font-size: 0.8rem;
            color: #CBB6A5;
            margin-top: 1.5rem;
            line-height: 1.5;
        }

        .info-note strong {
            color: #FFA852;
            display: block;
            margin-bottom: 0.25rem;
            font-size: 0.78rem;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        /* Footer */
        .page-footer {
            text-align: center;
            margin-top: 2rem;
            color: #4A3828;
            font-size: 0.78rem;
            z-index: 1;
        }
    </style>
</head>
<body>
    <!-- Animated background blobs -->
    <div class="bg-blob bg-blob-1"></div>
    <div class="bg-blob bg-blob-2"></div>

    <div class="login-card">
        <!-- Brand -->
        <div class="brand-area">
            <div class="brand-icon">📡</div>
            <div class="brand-name">Nexus</div>
            <div class="brand-subtitle">Helper Scanner Portal</div>
        </div>

        <!-- Error -->
        <?php if ($message): ?>
            <div class="error-msg">
                <span>⚠️</span>
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- Form -->
        <form method="POST" action="" autocomplete="off">
            <div class="section-title">Helper Passcode</div>
            <div class="input-wrapper">
                <span class="input-icon">🔑</span>
                <input
                    type="password"
                    id="helper_key"
                    name="helper_key"
                    class="passcode-input"
                    placeholder="Enter your access passcode"
                    autocomplete="off"
                    autocorrect="off"
                    autocapitalize="off"
                    spellcheck="false"
                    required
                    autofocus
                >
                <button type="button" class="input-toggle-btn" id="toggle-visibility" aria-label="Show/Hide passcode">👁️</button>
            </div>

            <button type="submit" class="submit-btn" id="submit-btn">
                <span>🚀</span>
                <span>Open Scanner</span>
            </button>
        </form>

        <!-- Info note -->
        <div class="info-note">
            <strong>📋 For Event Helpers Only</strong>
            Enter the passcode provided by your event administrator. Once verified, your phone's camera will activate automatically for QR scanning.
        </div>
    </div>

    <p class="page-footer">⚡ Nexus · Secure Helper Access</p>

    <script>
        // Toggle password visibility
        const toggleBtn = document.getElementById('toggle-visibility');
        const input = document.getElementById('helper_key');
        toggleBtn.addEventListener('click', () => {
            if (input.type === 'password') {
                input.type = 'text';
                toggleBtn.textContent = '🙈';
            } else {
                input.type = 'password';
                toggleBtn.textContent = '👁️';
            }
        });

        // Button loading state
        document.querySelector('form').addEventListener('submit', function() {
            const btn = document.getElementById('submit-btn');
            btn.innerHTML = '<span>⏳</span><span>Verifying...</span>';
            btn.disabled = true;
            btn.style.opacity = '0.8';
        });
    </script>
</body>
</html>

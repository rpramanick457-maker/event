<?php
ob_start();
require_once 'db_connect.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$error = '';
$success = '';

// Auto-login via helper_key in query string (public link)
if (isset($_GET['helper_key']) && empty($_SESSION['helper_key'])) {
    $key = trim($_GET['helper_key']);
    if ($key !== '') {
        // Validate key against DB
        try {
            $stmt = $conn->prepare('SELECT * FROM helpers WHERE helper_key = ?');
            $stmt->execute([$key]);
            $h = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($h) {
                $_SESSION['helper_key'] = $key;
                // Redirect to clean URL without query parameter
                header('Location: scanner.php');
                exit();
            }
        } catch (PDOException $e) {
            // ignore and fall through to login form
        }
    }
}

// Handle helper logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    unset($_SESSION['helper_key']);
    header('Location: scanner.php');
    exit();
}

// Handle Passcode verification form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_login'])) {
    $passcode = trim($_POST['passcode']);
    if (empty($passcode)) {
        $error = 'Please enter your Helper Passcode.';
    } else {
        try {
            $stmt = $conn->prepare("SELECT * FROM helpers WHERE helper_key = ?");
            $stmt->execute([$passcode]);
            $helper = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($helper) {
                $_SESSION['helper_key'] = $passcode;
                header('Location: scanner.php');
                exit();
            } else {
                $error = 'Invalid Helper Passcode. Access Denied.';
            }
        } catch (PDOException $e) {
            $error = 'Database connection error: ' . $e->getMessage();
        }
    }
}

// Check if helper is already logged in/authorized via session
$helper = null;
$helper_key = $_SESSION['helper_key'] ?? '';
if (!empty($helper_key)) {
    try {
        $stmt = $conn->prepare("SELECT * FROM helpers WHERE helper_key = ?");
        $stmt->execute([$helper_key]);
        $helper = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // If helper record was deleted in admin panel, revoke session
        if (!$helper) {
            unset($_SESSION['helper_key']);
            $error = 'Your access key has been revoked by the administrator.';
        }
    } catch (PDOException $e) {
        $error = 'Database error: ' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Universal Mobile Scanner | Nexus</title>
    <link rel="stylesheet" href="css/style.css?v=1.6">
    <!-- html5-qrcode scanner library loaded via CDN -->
    <script src="https://unpkg.com/html5-qrcode" type="text/javascript"></script>
    <script src="js/theme.js?v=1.6"></script>
    <style>
        /* Mobile-optimized layout styles */
        .scanner-container {
            max-width: 500px;
            margin: 1.5rem auto 4rem auto;
            padding: 0 1rem;
        }
        
        .helper-badge {
            background: var(--primary-glow);
            border: 1px solid var(--primary);
            color: var(--primary);
            padding: 0.6rem 1rem;
            border-radius: var(--radius-sm);
            font-size: 0.9rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 1.5rem;
        }

        #reader {
            border: none !important;
            background: #000;
            border-radius: var(--radius-md);
            overflow: hidden;
            box-shadow: 0 8px 30px rgba(0, 0, 0, 0.3);
        }
        #reader video {
            object-fit: cover !important;
            width: 100% !important;
            height: 100% !important;
        }
        #reader img {
            display: none !important; /* Hide standard scans symbol */
        }
        #reader__dashboard_section_csr button, #reader__dashboard_section_swaplink {
            background: var(--primary);
            border: 1px solid var(--primary);
            color: white;
            padding: 0.6rem 1.2rem;
            border-radius: var(--radius-sm);
            font-weight: 600;
            cursor: pointer;
            font-family: var(--font-body);
            transition: var(--transition);
            margin: 5px;
            font-size: 0.9rem;
        }
        #reader__dashboard_section_csr button:hover {
            background: var(--secondary);
            border-color: var(--secondary);
            box-shadow: 0 0 15px var(--primary-glow);
        }
        #reader__camera_selection {
            background: var(--bg-input);
            border: 1px solid var(--border-color);
            color: var(--text-primary);
            padding: 0.5rem;
            border-radius: var(--radius-sm);
            margin: 10px 5px;
            width: calc(100% - 10px);
            font-family: var(--font-body);
        }
        
        .scanner-manual-input {
            margin-top: 1.5rem;
            display: flex;
            gap: 0.5rem;
        }
        
        .result-card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: var(--radius-md);
            padding: 1.25rem;
            margin-top: 1.5rem;
            box-shadow: 0 8px 32px rgba(0,0,0,0.05);
            animation: slideUp 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        }
        
        @keyframes slideUp {
            from { transform: translateY(15px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }

        .result-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            font-weight: 800;
            font-family: var(--font-heading);
            font-size: 1.2rem;
            margin-bottom: 0.75rem;
            border-bottom: 1px solid var(--border-color);
            padding-bottom: 0.5rem;
        }
        
        .result-indicator {
            width: 14px;
            height: 14px;
            border-radius: 50%;
            display: inline-block;
        }
        .result-indicator.success {
            background: var(--success);
            box-shadow: 0 0 10px var(--success);
        }
        .result-indicator.error {
            background: var(--danger);
            box-shadow: 0 0 10px var(--danger);
        }
        
        .result-details-grid {
            display: grid;
            grid-template-columns: 100px 1fr;
            gap: 0.5rem;
            font-size: 0.9rem;
            margin-top: 0.75rem;
        }
        
        .result-label {
            color: var(--text-secondary);
            font-weight: 600;
        }
        
        .result-value {
            color: var(--text-primary);
            font-weight: 500;
        }
        
        .result-screenshot {
            max-width: 100%;
            max-height: 150px;
            object-fit: contain;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border-color);
            margin-top: 0.5rem;
            cursor: pointer;
        }
        
        /* Custom Interactive Scanning HUD Overlay */
        #scanner-laser {
            position: absolute;
            inset: 0;
            z-index: 5;
            pointer-events: none;
            display: none;
        }

        /* Corner brackets */
        .scanner-corner {
            position: absolute;
            width: 24px;
            height: 24px;
            border: 3px solid transparent;
            transition: border-color 0.3s ease;
        }
        .scanner-corner.top-left {
            top: 20px;
            left: 20px;
            border-top-color: var(--primary);
            border-left-color: var(--primary);
            border-top-left-radius: var(--radius-sm);
        }
        .scanner-corner.top-right {
            top: 20px;
            right: 20px;
            border-top-color: var(--primary);
            border-right-color: var(--primary);
            border-top-right-radius: var(--radius-sm);
        }
        .scanner-corner.bottom-left {
            bottom: 20px;
            left: 20px;
            border-bottom-color: var(--primary);
            border-left-color: var(--primary);
            border-bottom-left-radius: var(--radius-sm);
        }
        .scanner-corner.bottom-right {
            bottom: 20px;
            right: 20px;
            border-bottom-color: var(--primary);
            border-right-color: var(--primary);
            border-bottom-right-radius: var(--radius-sm);
        }

        /* Pulsing corners state */
        @keyframes corner-pulse {
            0%, 100% { opacity: 0.6; transform: scale(1); }
            50% { opacity: 1; transform: scale(1.05); }
        }
        .scanner-corner {
            animation: corner-pulse 2s infinite ease-in-out;
        }

        /* Center target ring */
        .scanner-target {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 80px;
            height: 80px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .scanner-target-ring {
            width: 100%;
            height: 100%;
            border: 2px dashed rgba(255, 107, 0, 0.4);
            border-radius: 50%;
            animation: rotate-ring 8s linear infinite;
        }
        @keyframes rotate-ring {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }

        /* Radar scanning sweep element */
        .scanner-sweep {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: linear-gradient(180deg, 
                rgba(255, 107, 0, 0) 0%, 
                rgba(255, 107, 0, 0.03) 70%, 
                rgba(255, 107, 0, 0.2) 99%, 
                rgba(255, 107, 0, 0.5) 100%
            );
            border-bottom: 2px solid var(--primary);
            animation: radar-sweep 2.5s ease-in-out infinite;
            opacity: 0.8;
        }
        @keyframes radar-sweep {
            0% { transform: translateY(-100%); }
            50% { transform: translateY(0%); }
            100% { transform: translateY(100%); }
        }

        /* Interactive feedback HUD states */
        #scanner-laser.success .scanner-corner {
            border-color: var(--success) !important;
            animation: success-flash 0.5s ease-out;
        }
        #scanner-laser.success .scanner-sweep {
            border-bottom-color: var(--success) !important;
            background: linear-gradient(180deg, rgba(16,185,129,0) 70%, rgba(16,185,129,0.3) 100%) !important;
        }
        #scanner-laser.success .scanner-target-ring {
            border-color: var(--success) !important;
        }
        #scanner-laser.error .scanner-corner {
            border-color: var(--danger) !important;
            animation: error-shake 0.4s ease-in-out;
        }
        #scanner-laser.error .scanner-sweep {
            border-bottom-color: var(--danger) !important;
            background: linear-gradient(180deg, rgba(239,68,68,0) 70%, rgba(239,68,68,0.3) 100%) !important;
        }
        #scanner-laser.error .scanner-target-ring {
            border-color: var(--danger) !important;
        }

        @keyframes success-flash {
            0% { opacity: 1; transform: scale(1.1); }
            100% { opacity: 1; transform: scale(1); }
        }
        @keyframes error-shake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-6px); }
            40%, 80% { transform: translateX(6px); }
        }
    </style>
</head>
<body>
    <!-- Top glass header -->
    <header>
        <div class="nav-container" style="padding: 1rem 2rem; flex-direction: row !important; justify-content: space-between !important; align-items: center !important;">
            <a href="scanner.php" class="logo-container">
                <img src="images/nexus_logo.png" alt="Nexus Logo" class="logo-img">
                <span class="logo-text">Nexus <span style="font-weight: 400; font-size: 1.1rem; color: var(--text-secondary);">Scanner</span></span>
            </a>
            <button class="theme-toggle-btn" aria-label="Toggle Theme">🌙</button>
        </div>
    </header>

    <div class="scanner-container">
        <!-- Message Alerts -->
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger" style="margin-top: 1rem;">
                <span>⚠️</span> <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if (!$helper): ?>
            <!-- PASSCODE LOGIN FORM -->
            <div class="glass-panel" style="padding: 2.5rem 2rem; margin-top: 2rem;">
                <div style="text-align: center; margin-bottom: 2rem;">
                    <span style="font-size: 3rem;">🔐</span>
                    <h2 style="font-size: 1.5rem; margin-top: 1rem;">Helper Access Required</h2>
                    <p style="color: var(--text-secondary); font-size: 0.9rem; margin-top: 0.5rem;">
                        Please enter the Helper Passcode assigned to you by the event administrator.
                    </p>
                </div>
                
                <form action="scanner.php" method="POST">
                    <div class="form-group">
                        <label class="form-label" for="passcode-input">Helper Passcode / Key</label>
                        <input type="text" id="passcode-input" name="passcode" class="form-control" placeholder="E.g. f6ebebdbce39e..." required autofocus>
                    </div>
                    
                    <button type="submit" name="action_login" class="btn btn-primary" style="width: 100%; height: 46px; margin-top: 1.5rem; font-weight: 700;">
                        Unlock Scanner
                    </button>
                </form>
            </div>
        <?php else: ?>
            <!-- ACTIVE SCANNER VIEW -->
            <div class="helper-badge">
                <div style="display: flex; align-items: center; gap: 0.5rem;">
                    <span>🛡️</span>
                    <span>Helper: <strong><?php echo htmlspecialchars($helper['name']); ?></strong></span>
                </div>
                <a href="scanner.php?action=logout" style="font-size: 0.8rem; color: var(--danger); font-weight: 700; text-transform: uppercase;">Logout</a>
            </div>

            <div class="glass-panel" style="padding: 1.5rem;">
                <h2 style="font-size: 1.4rem; margin-bottom: 0.5rem;">Student Check-In</h2>
                <p style="color: var(--text-secondary); font-size: 0.85rem; margin-bottom: 1.5rem;">
                    Position the student's ticket QR code inside the camera box. The scanner automatically logs entry on the first scan and exit on the second.
                </p>
                
                <!-- Camera Viewport with laser overlay -->
                <div style="position: relative; border-radius: var(--radius-md); overflow: hidden; background: #000; aspect-ratio: 1; max-width: 100%; margin: 0 auto 1.5rem auto;">
                    <div id="reader" style="width: 100%; height: 100%; border: none;"></div>
                    <div class="scanner-laser" id="scanner-laser" style="display: none;">
                        <!-- Corner brackets -->
                        <div class="scanner-corner top-left"></div>
                        <div class="scanner-corner top-right"></div>
                        <div class="scanner-corner bottom-left"></div>
                        <div class="scanner-corner bottom-right"></div>
                        <!-- Target crosshair / scanning ring in the middle -->
                        <div class="scanner-target">
                            <div class="scanner-target-ring"></div>
                        </div>
                        <!-- Radar sweeping line -->
                        <div class="scanner-sweep"></div>
                    </div>
                    <!-- Camera Offline Placeholder -->
                    <div id="camera-placeholder" style="position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; background: rgba(18, 14, 11, 0.95); color: var(--text-muted); z-index: 4;">
                        <span style="font-size: 3rem; margin-bottom: 0.5rem; filter: drop-shadow(0 0 10px rgba(0,0,0,0.5));">📷</span>
                        <span style="font-size: 1rem; font-weight: 600; color: var(--text-secondary);">Camera is Offline</span>
                        <span style="font-size: 0.8rem; color: var(--text-muted); margin-top: 0.25rem;">Start camera below or scan an image file</span>
                    </div>
                </div>

                <!-- Custom Action Controls -->
                <div style="display: flex; gap: 1rem; justify-content: center; margin-bottom: 1.5rem; flex-wrap: wrap;">
                    <button id="btn-toggle-camera" class="btn btn-primary" onclick="toggleCamera()" style="display: flex; align-items: center; gap: 0.5rem; padding: 0.75rem 1.5rem; font-weight: 700;">
                        <span>📷</span> Start Camera
                    </button>
                    <button id="btn-scan-file" class="btn btn-secondary" onclick="triggerFileScan()" style="display: flex; align-items: center; gap: 0.5rem; padding: 0.75rem 1.5rem; font-weight: 700;">
                        <span>📁</span> Scan Image File
                    </button>
                    <input type="file" id="qr-file-input" accept="image/*" style="display: none;" onchange="handleFileScan(this)">
                </div>

                <!-- Camera Switcher Dropdown (Hidden by default unless multiple cameras exist) -->
                <div id="camera-select-container" style="display: none; text-align: center; margin-bottom: 1.5rem;">
                    <label for="camera-select" style="font-size: 0.8rem; font-weight: 600; color: var(--text-muted); display: block; margin-bottom: 0.4rem; text-transform: uppercase; letter-spacing: 0.05em;">Select Camera Source</label>
                    <select id="camera-select" class="form-control" style="max-width: 300px; margin: 0 auto; height: 38px; font-size: 0.9rem;" onchange="switchCamera(this.value)">
                        <!-- Populated dynamically -->
                    </select>
                </div>
                
                <!-- Manual fallback input -->
                <div class="scanner-manual-input">
                    <input type="text" id="manual-token-input" class="form-control" placeholder="Paste ticket token manually..." style="height: 40px;">
                    <button class="btn btn-primary" onclick="submitManualToken()" style="height: 40px; padding: 0 1.25rem; font-size: 0.9rem; flex-shrink: 0;">Verify</button>
                </div>
            </div>

            <!-- Live Scan Status Feed -->
            <div id="scan-feedback-default" style="text-align: center; padding: 3rem 1rem; color: var(--text-muted); border: 1px dashed var(--border-color); border-radius: var(--radius-md); margin-top: 1.5rem;">
                <span style="font-size: 2rem;">📸</span>
                <h4 style="margin-top: 0.5rem; font-size: 1rem;">Awaiting Scan</h4>
                <p style="font-size: 0.8rem; margin-top: 0.25rem;">Camera viewport will display scans above.</p>
            </div>

            <div id="scan-feedback-card" class="result-card" style="display: none;">
                <div class="result-header">
                    <div id="result-indicator" class="result-indicator"></div>
                    <span id="result-header-text">Access Granted</span>
                </div>
                
                <div id="result-message" style="font-size: 1rem; font-weight: 700; margin-bottom: 0.5rem;">
                    -
                </div>
                
                <div class="result-details-grid">
                    <div class="result-label">Student:</div>
                    <div class="result-value" id="result-student-name">-</div>
                    
                    <div class="result-label">Roll/Batch:</div>
                    <div class="result-value" id="result-roll-batch">-</div>
                    
                    <div class="result-label">Event:</div>
                    <div class="result-value" id="result-event-name" style="color: var(--primary); font-weight: bold;">-</div>
                    
                    <div class="result-label">Role:</div>
                    <div class="result-value" id="result-role">-</div>
                    
                    <div class="result-label">Food:</div>
                    <div class="result-value" id="result-food">-</div>
                    
                    <div class="result-label">Time:</div>
                    <div class="result-value" id="result-timestamp" style="font-family: monospace; color: var(--text-secondary); font-size: 0.85rem;">-</div>
                </div>
                
                <div style="border-top: 1px solid var(--border-color); padding-top: 0.75rem; margin-top: 0.75rem; display: flex; flex-direction: column;" id="receipt-section">
                    <span class="result-label" style="font-size: 0.8rem; margin-bottom: 0.25rem;">Uploaded Payment Receipt:</span>
                    <img id="result-receipt-img" class="result-screenshot" src="#" alt="Receipt" onclick="openLightbox(this.src)">
                    <div id="result-receipt-free-label" style="display: none; padding: 0.75rem; background: rgba(16, 185, 129, 0.15); border: 1px dashed rgba(16, 185, 129, 0.4); color: #34d399; border-radius: var(--radius-sm); text-align: center; font-weight: bold; font-size: 0.9rem;">
                        🎟️ Free Entry Event
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <!-- Payment Screenshot Lightbox Modal -->
    <div id="lightbox-modal" class="modal" onclick="closeLightbox()">
        <div class="modal-content" style="background: transparent; border: none; box-shadow: none; max-width: 800px; display: flex; justify-content: center; align-items: center;" onclick="event.stopPropagation()">
            <span style="position: absolute; top: -2.5rem; right: 0; font-size: 2rem; color: white; cursor: pointer;" onclick="closeLightbox()">&times;</span>
            <img id="lightbox-img" src="" alt="Full size receipt" style="max-width: 100%; max-height: 80vh; border-radius: var(--radius-sm); border: 2px solid white; box-shadow: 0 10px 40px rgba(0,0,0,0.8); object-fit: contain;">
        </div>
    </div>

    <?php if ($helper): ?>
    <script>
        // Camera QR Code scanner initialization
        const helperKey = "<?php echo htmlspecialchars($helper_key); ?>";
        let html5QrCode = null;
        let isCameraActive = false;
        let isProcessing = false;
        let camerasList = [];

        // Configuration for QR scanner
        const qrConfig = {
            fps: 15,
            qrbox: function(width, height) {
                // Return a box size based on container size (larger for mobile alignment)
                const minDim = Math.min(width, height);
                const size = Math.floor(minDim * 0.85);
                return {
                    width: size,
                    height: size
                };
            }
        };
        
        function detectCameras() {
            Html5Qrcode.getCameras().then(devices => {
                camerasList = devices;
                const select = document.getElementById('camera-select');
                if (devices && devices.length > 0) {
                    select.innerHTML = '';
                    devices.forEach((device, index) => {
                        const opt = document.createElement('option');
                        opt.value = device.id;
                        opt.textContent = device.label || `Camera ${index + 1}`;
                        select.appendChild(opt);
                    });
                    
                    if (devices.length > 1) {
                        document.getElementById('camera-select-container').style.display = 'block';
                    } else {
                        document.getElementById('camera-select-container').style.display = 'none';
                    }
                }
            }).catch(err => {
                console.warn("Error getting cameras: ", err);
            });
        }

        function toggleCamera() {
            if (isCameraActive) {
                stopCamera();
            } else {
                startCamera();
            }
        }
        
        function startCamera() {
            if (isCameraActive) return;
            
            const btn = document.getElementById('btn-toggle-camera');
            const placeholder = document.getElementById('camera-placeholder');
            const laser = document.getElementById('scanner-laser');
            const select = document.getElementById('camera-select');
            
            let cameraSource = { facingMode: "environment" };
            if (select && select.value) {
                cameraSource = { deviceId: { exact: select.value } };
            }
            
            btn.disabled = true;
            btn.innerHTML = '<span>⏳</span> Starting...';
            
            html5QrCode.start(
                cameraSource,
                qrConfig,
                onScanSuccess,
                onScanFailure
            ).then(() => {
                isCameraActive = true;
                btn.disabled = false;
                btn.innerHTML = '<span>⏹️</span> Stop Camera';
                btn.className = 'btn btn-secondary';
                
                placeholder.style.display = 'none';
                laser.style.display = 'block';
                
                // Fetch cameras to populate selector dropdown
                detectCameras();
            }).catch(err => {
                console.error("Failed to start camera", err);
                alert("Camera access denied or error starting camera: " + err);
                btn.disabled = false;
                btn.innerHTML = '<span>📷</span> Start Camera';
                btn.className = 'btn btn-primary';
                placeholder.style.display = 'flex';
                laser.style.display = 'none';
            });
        }
        
        function stopCamera() {
            if (!isCameraActive) return;
            
            const btn = document.getElementById('btn-toggle-camera');
            const placeholder = document.getElementById('camera-placeholder');
            const laser = document.getElementById('scanner-laser');
            
            btn.disabled = true;
            btn.innerHTML = '<span>⏳</span> Stopping...';
            
            html5QrCode.stop().then(() => {
                isCameraActive = false;
                btn.disabled = false;
                btn.innerHTML = '<span>📷</span> Start Camera';
                btn.className = 'btn btn-primary';
                
                placeholder.style.display = 'flex';
                laser.style.display = 'none';
            }).catch(err => {
                console.error("Failed to stop camera", err);
                btn.disabled = false;
            });
        }

        function switchCamera(cameraId) {
            if (isCameraActive) {
                html5QrCode.stop().then(() => {
                    isCameraActive = false;
                    startCamera();
                }).catch(err => {
                    console.error("Error stopping camera for switch: ", err);
                });
            }
        }

        // Image file scanning implementation
        function triggerFileScan() {
            document.getElementById('qr-file-input').click();
        }

        function handleFileScan(input) {
            if (input.files && input.files[0]) {
                const file = input.files[0];
                
                showScanFeedbackLoading();
                
                html5QrCode.scanFile(file, true)
                    .then(decodedText => {
                        verifyToken(decodedText);
                        input.value = '';
                    })
                    .catch(err => {
                        console.warn("Scan file error: ", err);
                        showScanFeedback({
                            success: false,
                            message: "Access Denied: No valid QR code could be scanned from the uploaded image."
                        });
                        input.value = '';
                    });
            }
        }

        function showScanFeedbackLoading() {
            let defaultFeed = document.getElementById('scan-feedback-default');
            let cardFeed = document.getElementById('scan-feedback-card');
            
            defaultFeed.style.display = 'none';
            cardFeed.style.display = 'block';
            
            let indicator = document.getElementById('result-indicator');
            let headerText = document.getElementById('result-header-text');
            let message = document.getElementById('result-message');
            let name = document.getElementById('result-student-name');
            let rollBatch = document.getElementById('result-roll-batch');
            let eventName = document.getElementById('result-event-name');
            let role = document.getElementById('result-role');
            let food = document.getElementById('result-food');
            let timestamp = document.getElementById('result-timestamp');
            let receiptSection = document.getElementById('receipt-section');
            
            indicator.className = 'result-indicator';
            indicator.style.background = 'var(--warning)';
            indicator.style.boxShadow = '0 0 10px var(--warning)';
            headerText.textContent = 'Processing File...';
            headerText.style.color = 'var(--warning)';
            message.textContent = 'Analyzing image for QR code token...';
            message.style.color = '#ffffff';
            
            name.textContent = '-';
            rollBatch.textContent = '-';
            eventName.textContent = '-';
            role.textContent = '-';
            food.textContent = '-';
            timestamp.textContent = '-';
            receiptSection.style.display = 'none';
        }
        
        function onScanSuccess(decodedText, decodedResult) {
            if (isProcessing) return;
            // Decoded text represents the token string. Verify with backend.
            verifyToken(decodedText);
        }
        
        function onScanFailure(error) {
            // Keep scanning silently
        }
        
        function submitManualToken() {
            if (isProcessing) return;
            let tokenInput = document.getElementById('manual-token-input');
            let val = tokenInput.value.trim();
            if (val !== '') {
                verifyToken(val);
                tokenInput.value = '';
            } else {
                alert("Please enter a valid ticket token.");
            }
        }
        
        // Call scan_handler.php API
        function verifyToken(tokenString) {
            isProcessing = true;
            
            let token = tokenString;
            try {
                // If the scanned text is a URL, extract the token query parameter
                if (tokenString.startsWith('http://') || tokenString.startsWith('https://')) {
                    const url = new URL(tokenString);
                    const urlToken = url.searchParams.get('token');
                    if (urlToken) {
                        token = urlToken;
                    }
                }
            } catch (e) {
                console.error("Error parsing scanned URL:", e);
            }
            
            fetch('scan_handler.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({ 
                    token: token,
                    helper_key: helperKey
                })
            })
            .then(res => res.json())
            .then(data => {
                showScanFeedback(data);
                // Keep the processing lock active for 2.5 seconds to prevent double scanning
                setTimeout(() => {
                    isProcessing = false;
                }, 2500);
            })
            .catch(err => {
                console.error("Verification request failed", err);
                showScanFeedback({
                    success: false,
                    message: "Connection Error: Failed to reach verification server."
                });
                // Release lock quickly on error
                setTimeout(() => {
                    isProcessing = false;
                }, 1500);
            });
        }


        function showScanFeedback(response) {
            let defaultFeed = document.getElementById('scan-feedback-default');
            let cardFeed = document.getElementById('scan-feedback-card');
            
            defaultFeed.style.display = 'none';
            cardFeed.style.display = 'block';
            
            let indicator = document.getElementById('result-indicator');
            let headerText = document.getElementById('result-header-text');
            let message = document.getElementById('result-message');
            let name = document.getElementById('result-student-name');
            let rollBatch = document.getElementById('result-roll-batch');
            let eventName = document.getElementById('result-event-name');
            let role = document.getElementById('result-role');
            let food = document.getElementById('result-food');
            let timestamp = document.getElementById('result-timestamp');
            let receiptImg = document.getElementById('result-receipt-img');
            let receiptSection = document.getElementById('receipt-section');
            
            // Apply success/error class to scanner-laser for interactive feedback animation
            const laser = document.getElementById('scanner-laser');
            if (laser) {
                laser.classList.remove('success', 'error');
                if (response.success) {
                    laser.classList.add('success');
                } else {
                    laser.classList.add('error');
                }
                
                // Reset feedback animation state after 1.5 seconds
                setTimeout(() => {
                    laser.classList.remove('success', 'error');
                }, 1500);
            }
            
            // Reset style overrides from loading state
            indicator.style.background = '';
            indicator.style.boxShadow = '';
            
            if (response.success) {
                // SUCCESS (Entry or Exit check-in)
                indicator.className = 'result-indicator success';
                headerText.style.color = 'var(--success)';
                message.style.color = '#ffffff';
                
                if (response.type === 'entry') {
                    headerText.textContent = 'Access Granted (Check-In)';
                    message.textContent = 'ENTRY MARKED SUCCESSFUL 🟢';
                } else {
                    headerText.textContent = 'Ticket Deactivated (Check-Out)';
                    message.textContent = 'EXIT MARKED SUCCESSFUL 🔵';
                }
                
                name.textContent = response.student_name;
                rollBatch.textContent = response.roll_no + ' (' + response.batch + ')';
                eventName.textContent = response.event_title;
                role.textContent = response.event_role;
                food.textContent = response.food_preference;
                timestamp.textContent = response.timestamp;
                
                if (response.payment_screenshot === 'free') {
                     receiptImg.style.display = 'none';
                     document.getElementById('result-receipt-free-label').style.display = 'block';
                } else {
                     receiptImg.src = response.payment_screenshot;
                     receiptImg.style.display = 'block';
                     document.getElementById('result-receipt-free-label').style.display = 'none';
                }
                receiptSection.style.display = 'flex';
                
            } else {
                // FAIL
                indicator.className = 'result-indicator error';
                headerText.textContent = 'Access Denied';
                headerText.style.color = 'var(--danger)';
                message.textContent = response.message;
                message.style.color = 'var(--danger)';
                

                name.textContent = '-';
                rollBatch.textContent = '-';
                eventName.textContent = '-';
                role.textContent = '-';
                food.textContent = '-';
                timestamp.textContent = '-';
                
                receiptImg.src = '#';
                document.getElementById('result-receipt-free-label').style.display = 'none';
                receiptSection.style.display = 'none';
            }
            
            // Scroll results card into view on small screens
            cardFeed.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        // Image Lightbox Viewer
        function openLightbox(src) {
            document.getElementById('lightbox-img').src = src;
            document.getElementById('lightbox-modal').classList.add('active');
        }

        function closeLightbox() {
            document.getElementById('lightbox-modal').classList.remove('active');
        }

        // Initialize camera object on page load
        document.addEventListener('DOMContentLoaded', () => {
            html5QrCode = new Html5Qrcode("reader");
            detectCameras();
        });
    </script>
    <?php endif; ?>
</body>
</html>

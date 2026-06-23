<?php
// mobile_scanner.php – Universal Mobile QR Scanner
// Accessible via: /event/mobile_scanner.php?helper_key=XXXX
// Works for any helper on any mobile browser — no app install needed.
ob_start();
require_once 'db_connect.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

$error = '';
$helper = null;
$helper_key = '';

// --- AUTHENTICATION FLOW ---

// 1. Handle helper_key passed in URL query string (direct link from admin share)
if (isset($_GET['helper_key']) && !empty($_GET['helper_key'])) {
    $key = trim($_GET['helper_key']);
    try {
        $stmt = $conn->prepare('SELECT * FROM helpers WHERE helper_key = ?');
        $stmt->execute([$key]);
        $h = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($h) {
            $_SESSION['helper_key'] = $key;
            $_SESSION['helper_name'] = $h['name'];
            // Redirect to clean URL (removes key from browser URL bar for security)
            header('Location: mobile_scanner.php');
            exit();
        } else {
            $error = 'Invalid or revoked access link. Please contact your administrator.';
        }
    } catch (PDOException $e) {
        $error = 'Database error. Please try again.';
    }
}

// 2. Handle logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    unset($_SESSION['helper_key'], $_SESSION['helper_name']);
    header('Location: helper_scanner.php');
    exit();
}

// 3. Verify session-based helper
$helper_key = $_SESSION['helper_key'] ?? '';
if (!empty($helper_key)) {
    try {
        $stmt = $conn->prepare("SELECT * FROM helpers WHERE helper_key = ?");
        $stmt->execute([$helper_key]);
        $helper = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$helper) {
            unset($_SESSION['helper_key'], $_SESSION['helper_name']);
            $error = 'Your access key has been revoked by the administrator.';
        }
    } catch (PDOException $e) {
        $error = 'Database error: ' . $e->getMessage();
    }
}

// 4. Also allow admin session
if (!$helper && isset($_SESSION['user_id']) && $_SESSION['user_role'] === 'admin') {
    $helper = ['name' => $_SESSION['user_name'] ?? 'Administrator', 'helper_key' => 'admin'];
    $helper_key = 'admin';
}

// If no valid session, redirect to login
if (!$helper && empty($error)) {
    header('Location: helper_scanner.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="theme-color" content="#0A0706">
    <title>QR Scanner | Nexus</title>
    <link rel="manifest" href="manifest.json">
    <!-- html5-qrcode library -->
    <script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
    <link rel="stylesheet" href="css/style.css?v=2.0">
    <style>
        /* ===========================
           RESET & BASE — FULLSCREEN
        =========================== */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        html, body {
            height: 100%;
            width: 100%;
            overflow: hidden;
            background: #0A0706;
            font-family: 'Plus Jakarta Sans', 'Outfit', sans-serif;
            color: #FCF8F4;
            -webkit-font-smoothing: antialiased;
            /* Support for safe area (iPhone notch/home bar) */
            padding-top: env(safe-area-inset-top);
            padding-bottom: env(safe-area-inset-bottom);
        }

        /* ===========================
           TOP STATUS BAR
        =========================== */
        .top-bar {
            position: fixed;
            top: env(safe-area-inset-top, 0);
            left: 0;
            right: 0;
            z-index: 100;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.75rem 1.25rem;
            background: linear-gradient(180deg, rgba(10,7,6,0.95) 0%, transparent 100%);
            pointer-events: none;
        }

        .top-bar > * { pointer-events: all; }

        .top-bar-brand {
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            font-size: 1.05rem;
            color: #FCF8F4;
            letter-spacing: -0.02em;
        }
        .top-bar-brand span { color: #FFA852; }

        .top-bar-right {
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }

        .helper-pill {
            background: rgba(255,168,82,0.12);
            border: 1px solid rgba(255,168,82,0.25);
            color: #FFA852;
            border-radius: 100px;
            padding: 0.3rem 0.75rem;
            font-size: 0.78rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 0.35rem;
            max-width: 140px;
            overflow: hidden;
        }

        .helper-pill span:last-child {
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .logout-btn {
            background: rgba(248, 113, 113, 0.1);
            border: 1px solid rgba(248, 113, 113, 0.25);
            color: #F87171;
            border-radius: 100px;
            padding: 0.3rem 0.75rem;
            font-size: 0.78rem;
            font-weight: 700;
            cursor: pointer;
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: 0.3rem;
            transition: all 0.2s;
        }
        .logout-btn:hover { background: rgba(248,113,113,0.2); }

        /* ===========================
           CAMERA VIEWPORT — FULLSCREEN
        =========================== */
        #camera-viewport {
            position: fixed;
            inset: 0;
            width: 100%;
            height: 100%;
            background: #000;
            overflow: hidden;
        }

        /* html5-qrcode internal video element full coverage */
        #reader {
            width: 100% !important;
            height: 100% !important;
            border: none !important;
        }

        #reader video {
            width: 100% !important;
            height: 100% !important;
            object-fit: cover !important;
            border: none !important;
        }

        /* Hide all internal html5-qrcode UI (we build our own) */
        #reader__dashboard,
        #reader__header_message,
        #reader__status_span,
        #reader__camera_selection,
        #reader__camera_permission_button,
        #reader__filescan_input,
        #reader img,
        #reader__scan_region > img {
            display: none !important;
        }

        /* ===========================
           SCANNING HUD OVERLAY
        =========================== */
        #scan-hud {
            position: fixed;
            inset: 0;
            pointer-events: none;
            z-index: 10;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
        }

        /* Dark vignette outside the target box */
        .hud-vignette {
            position: absolute;
            inset: 0;
            background:
                radial-gradient(
                    ellipse 55vw 55vw at center,
                    transparent 40%,
                    rgba(0,0,0,0.7) 100%
                );
        }

        /* Target scanning box */
        .scan-box {
            position: relative;
            width: min(72vw, 260px);
            height: min(72vw, 260px);
        }

        /* Animated corner brackets */
        .corner {
            position: absolute;
            width: 36px;
            height: 36px;
            border: 3px solid #FFA852;
        }
        .corner-tl { top: 0; left: 0; border-right: none; border-bottom: none; border-radius: 4px 0 0 0; }
        .corner-tr { top: 0; right: 0; border-left: none; border-bottom: none; border-radius: 0 4px 0 0; }
        .corner-bl { bottom: 0; left: 0; border-right: none; border-top: none; border-radius: 0 0 0 4px; }
        .corner-br { bottom: 0; right: 0; border-left: none; border-top: none; border-radius: 0 0 4px 0; }

        @keyframes corner-idle {
            0%, 100% { opacity: 0.6; }
            50% { opacity: 1; }
        }

        .corner { animation: corner-idle 2s ease-in-out infinite; }

        /* Laser scanning line */
        .scan-laser {
            position: absolute;
            left: 4px;
            right: 4px;
            height: 2px;
            background: linear-gradient(90deg, transparent, #FFA852, transparent);
            box-shadow: 0 0 10px 2px rgba(255, 168, 82, 0.5);
            animation: laser-sweep 2.2s ease-in-out infinite;
        }

        @keyframes laser-sweep {
            0% { top: 6px; opacity: 1; }
            48% { opacity: 1; }
            50% { top: calc(100% - 8px); opacity: 0.6; }
            52% { opacity: 0; }
            53% { top: 6px; opacity: 0; }
            55% { opacity: 1; }
            100% { top: 6px; }
        }

        /* Instruction label below box */
        .scan-instruction {
            position: absolute;
            bottom: -2.5rem;
            left: 50%;
            transform: translateX(-50%);
            white-space: nowrap;
            font-size: 0.82rem;
            font-weight: 600;
            color: rgba(255,255,255,0.5);
            letter-spacing: 0.04em;
            text-shadow: 0 1px 3px rgba(0,0,0,0.8);
        }

        /* SUCCESS state */
        #scan-hud.success .corner { border-color: #34D399 !important; animation: none; opacity: 1; }
        #scan-hud.success .scan-laser { background: linear-gradient(90deg, transparent, #34D399, transparent); box-shadow: 0 0 15px 3px rgba(52,211,153,0.6); animation: none; top: 50%; }
        #scan-hud.success .hud-vignette { background: radial-gradient(ellipse 60vw 60vw at center, rgba(52,211,153,0.08) 30%, rgba(0,0,0,0.6) 100%); }

        /* ERROR state */
        #scan-hud.error .corner { border-color: #F87171 !important; animation: corner-shake 0.4s ease; }
        #scan-hud.error .scan-laser { background: linear-gradient(90deg, transparent, #F87171, transparent); box-shadow: 0 0 15px 3px rgba(248,113,113,0.6); animation: none; top: 50%; }
        #scan-hud.error .hud-vignette { background: radial-gradient(ellipse 60vw 60vw at center, rgba(248,113,113,0.08) 30%, rgba(0,0,0,0.6) 100%); }

        @keyframes corner-shake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-5px); }
            40%, 80% { transform: translateX(5px); }
        }

        /* ===========================
           CAMERA OFFLINE PLACEHOLDER
        =========================== */
        #camera-offline {
            position: fixed;
            inset: 0;
            background: #0A0706;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 0.75rem;
            z-index: 20;
            text-align: center;
            padding: 2rem;
        }

        #camera-offline .offline-icon {
            font-size: 4rem;
            animation: float 3s ease-in-out infinite;
        }

        @keyframes float {
            0%, 100% { transform: translateY(0); }
            50% { transform: translateY(-10px); }
        }

        #camera-offline h2 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.3rem;
            font-weight: 800;
            color: #FCF8F4;
        }

        #camera-offline p {
            color: #8A7566;
            font-size: 0.88rem;
            max-width: 260px;
        }

        #start-camera-btn {
            margin-top: 0.5rem;
            background: linear-gradient(135deg, #FFA852, #FF6B35);
            border: none;
            border-radius: 50px;
            padding: 0.85rem 2.5rem;
            color: #130E0A;
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            font-size: 1rem;
            cursor: pointer;
            box-shadow: 0 4px 25px rgba(255,168,82,0.4);
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }
        #start-camera-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 35px rgba(255,168,82,0.5);
        }
        #start-camera-btn:active { transform: scale(0.97); }

        /* ===========================
           BOTTOM ACTION TRAY
        =========================== */
        .bottom-tray {
            position: fixed;
            bottom: 0;
            left: 0; right: 0;
            z-index: 50;
            padding: 0.75rem 1.25rem;
            padding-bottom: calc(0.75rem + env(safe-area-inset-bottom, 0px));
            background: linear-gradient(0deg, rgba(10,7,6,0.95) 60%, transparent 100%);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            pointer-events: none;
        }

        .bottom-tray > * { pointer-events: all; }

        /* Camera flip button */
        .cam-flip-btn {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.12);
            color: #FCF8F4;
            font-size: 1.2rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
            flex-shrink: 0;
        }
        .cam-flip-btn:hover { background: rgba(255,255,255,0.15); }
        .cam-flip-btn:active { transform: scale(0.9); }

        /* Scan status indicator center */
        .scan-status-center {
            flex: 1;
            text-align: center;
        }

        #scan-status-text {
            font-size: 0.82rem;
            color: rgba(255,255,255,0.4);
            font-weight: 600;
            letter-spacing: 0.04em;
        }

        /* Manual entry toggle button */
        .manual-btn {
            width: 48px;
            height: 48px;
            border-radius: 50%;
            background: rgba(255,255,255,0.08);
            border: 1px solid rgba(255,255,255,0.12);
            color: #FCF8F4;
            font-size: 1rem;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
            flex-shrink: 0;
        }
        .manual-btn:active { transform: scale(0.9); }

        /* ===========================
           RESULT SLIDE-UP SHEET
        =========================== */
        #result-sheet {
            position: fixed;
            bottom: 0;
            left: 0; right: 0;
            z-index: 200;
            border-radius: 24px 24px 0 0;
            padding: 1.75rem 1.5rem;
            padding-bottom: calc(1.75rem + env(safe-area-inset-bottom, 0px));
            transform: translateY(110%);
            transition: transform 0.45s cubic-bezier(0.16, 1, 0.3, 1);
            will-change: transform;
            max-height: 85vh;
            overflow-y: auto;
        }

        #result-sheet.active {
            transform: translateY(0);
        }

        #result-sheet.type-entry {
            background: linear-gradient(160deg, #0A2518 0%, #0D1F14 60%, #0A0706 100%);
            border-top: 2px solid rgba(52, 211, 153, 0.4);
            box-shadow: 0 -10px 60px rgba(52, 211, 153, 0.15);
        }

        #result-sheet.type-exit {
            background: linear-gradient(160deg, #1A1E2E 0%, #141824 60%, #0A0706 100%);
            border-top: 2px solid rgba(99, 179, 237, 0.4);
            box-shadow: 0 -10px 60px rgba(99, 179, 237, 0.12);
        }

        #result-sheet.type-error {
            background: linear-gradient(160deg, #2A0E0E 0%, #1E0B0B 60%, #0A0706 100%);
            border-top: 2px solid rgba(248, 113, 113, 0.4);
            box-shadow: 0 -10px 60px rgba(248, 113, 113, 0.12);
        }

        /* Sheet drag handle */
        .sheet-handle {
            width: 36px;
            height: 4px;
            border-radius: 2px;
            background: rgba(255,255,255,0.2);
            margin: 0 auto 1.5rem;
        }

        /* Result header */
        .result-header {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            margin-bottom: 1.25rem;
        }

        .result-icon {
            width: 52px;
            height: 52px;
            border-radius: 14px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
            flex-shrink: 0;
        }

        .type-entry .result-icon { background: rgba(52,211,153,0.15); border: 1px solid rgba(52,211,153,0.3); }
        .type-exit  .result-icon { background: rgba(99,179,237,0.15); border: 1px solid rgba(99,179,237,0.3); }
        .type-error .result-icon { background: rgba(248,113,113,0.15); border: 1px solid rgba(248,113,113,0.3); }

        .result-title-block { flex: 1; min-width: 0; }

        .result-action-label {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.1em;
            margin-bottom: 0.2rem;
        }
        .type-entry .result-action-label { color: #34D399; }
        .type-exit  .result-action-label { color: #63B3ED; }
        .type-error .result-action-label { color: #F87171; }

        .result-title {
            font-family: 'Outfit', sans-serif;
            font-size: 1.3rem;
            font-weight: 800;
            color: #FCF8F4;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .type-entry .result-title { color: #34D399; }
        .type-exit  .result-title { color: #63B3ED; }
        .type-error .result-title { color: #F87171; }

        /* Student info grid */
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.75rem;
            margin-top: 1rem;
        }

        .info-cell {
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.06);
            border-radius: 12px;
            padding: 0.75rem;
        }

        .info-cell-label {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: #8A7566;
            margin-bottom: 0.25rem;
        }

        .info-cell-value {
            font-size: 0.95rem;
            font-weight: 700;
            color: #FCF8F4;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        /* Event name spans full width */
        .info-cell.full-width { grid-column: 1 / -1; }

        .info-cell.full-width .info-cell-value {
            font-size: 1rem;
            color: #FFA852;
        }

        /* Timestamp row */
        .timestamp-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-top: 1rem;
            padding: 0.6rem 0;
            border-top: 1px solid rgba(255,255,255,0.06);
        }

        .timestamp-label { font-size: 0.78rem; color: #8A7566; }
        .timestamp-value { font-family: monospace; font-size: 0.82rem; color: #CBB6A5; }

        /* Auto-dismiss bar */
        .dismiss-bar-wrap {
            margin-top: 1.25rem;
            border-radius: 100px;
            overflow: hidden;
            background: rgba(255,255,255,0.06);
            height: 4px;
        }

        .dismiss-bar {
            height: 100%;
            border-radius: 100px;
            width: 100%;
            animation: none;
        }

        .type-entry .dismiss-bar { background: #34D399; }
        .type-exit  .dismiss-bar { background: #63B3ED; }
        .type-error .dismiss-bar { background: #F87171; }

        @keyframes shrink-bar {
            from { width: 100%; }
            to { width: 0%; }
        }

        .dismiss-bar.running {
            animation: shrink-bar 4s linear forwards;
        }

        /* Error message (full width) */
        .error-detail {
            background: rgba(248,113,113,0.08);
            border: 1px solid rgba(248,113,113,0.2);
            border-radius: 12px;
            padding: 1rem;
            font-size: 0.88rem;
            color: #FCA5A5;
            margin-top: 1rem;
            line-height: 1.5;
        }

        /* Tap to dismiss */
        #result-sheet-backdrop {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 190;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.3s;
        }

        #result-sheet-backdrop.active {
            opacity: 1;
            pointer-events: all;
        }

        /* ===========================
           MANUAL ENTRY DRAWER
        =========================== */
        #manual-drawer {
            position: fixed;
            bottom: 0;
            left: 0; right: 0;
            z-index: 180;
            background: rgba(20,15,11,0.97);
            border-top: 1px solid rgba(255,255,255,0.08);
            border-radius: 20px 20px 0 0;
            padding: 1.25rem 1.5rem;
            padding-bottom: calc(1.25rem + env(safe-area-inset-bottom, 0px));
            transform: translateY(110%);
            transition: transform 0.35s cubic-bezier(0.16, 1, 0.3, 1);
        }

        #manual-drawer.open {
            transform: translateY(0);
        }

        .manual-drawer-title {
            font-size: 0.85rem;
            font-weight: 700;
            color: #CBB6A5;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            margin-bottom: 0.85rem;
            text-align: center;
        }

        .manual-input-row {
            display: flex;
            gap: 0.6rem;
        }

        #manual-token {
            flex: 1;
            background: rgba(44, 32, 24, 0.9);
            border: 1.5px solid rgba(255,255,255,0.1);
            border-radius: 10px;
            padding: 0.75rem 1rem;
            color: #FCF8F4;
            font-family: monospace;
            font-size: 0.9rem;
        }

        #manual-token:focus {
            outline: none;
            border-color: rgba(255,168,82,0.5);
            box-shadow: 0 0 0 3px rgba(255,168,82,0.1);
        }

        #manual-verify-btn {
            background: linear-gradient(135deg, #FFA852, #FF6B35);
            border: none;
            border-radius: 10px;
            padding: 0.75rem 1.25rem;
            color: #130E0A;
            font-weight: 800;
            font-size: 0.9rem;
            cursor: pointer;
            white-space: nowrap;
        }

        #manual-verify-btn:active { transform: scale(0.96); }

        /* ===========================
           ERROR STATE (no auth)
        =========================== */
        #auth-error-screen {
            position: fixed;
            inset: 0;
            background: #0A0706;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 500;
            padding: 2rem;
            text-align: center;
            gap: 1rem;
        }

        /* ===========================
           LOADING SPINNER
        =========================== */
        #processing-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.6);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 300;
            display: none;
        }

        .spinner {
            width: 48px;
            height: 48px;
            border: 3px solid rgba(255,168,82,0.2);
            border-top-color: #FFA852;
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* ===========================
           INSECURE CONTEXT SCREEN
        =========================== */
        #insecure-context-screen {
            position: fixed;
            inset: 0;
            background: #0A0706;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 1.25rem;
            z-index: 400;
            text-align: center;
            padding: 2rem;
            overflow-y: auto;
        }

        #insecure-context-screen .warning-icon {
            font-size: 3.5rem;
            animation: pulse-ring 2s infinite;
        }

        @keyframes pulse-ring {
            0% { transform: scale(0.95); opacity: 0.8; }
            50% { transform: scale(1.05); opacity: 1; }
            100% { transform: scale(0.95); opacity: 0.8; }
        }

        #insecure-context-screen h2 {
            font-family: 'Outfit', sans-serif;
            font-size: 1.4rem;
            font-weight: 800;
            color: #FF6B35;
        }

        #insecure-context-screen .warning-desc {
            color: #CBB6A5;
            font-size: 0.9rem;
            max-width: 300px;
            line-height: 1.5;
        }

        #switch-https-btn {
            background: linear-gradient(135deg, #FFA852, #FF6B35);
            border: none;
            border-radius: 50px;
            padding: 0.85rem 2rem;
            color: #130E0A;
            font-family: 'Outfit', sans-serif;
            font-weight: 800;
            font-size: 0.95rem;
            cursor: pointer;
            box-shadow: 0 4px 25px rgba(255,168,82,0.3);
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }

        #switch-https-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 35px rgba(255,168,82,0.4);
        }

        #insecure-context-screen .instruction-box {
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 16px;
            padding: 1.25rem;
            max-width: 320px;
            text-align: left;
            margin-top: 0.5rem;
        }

        #insecure-context-screen .instruction-box h3 {
            font-family: 'Outfit', sans-serif;
            font-size: 0.85rem;
            font-weight: 700;
            text-transform: uppercase;
            color: #FFA852;
            margin-bottom: 0.75rem;
            letter-spacing: 0.05em;
        }

        .instruction-step {
            display: flex;
            gap: 0.75rem;
            margin-bottom: 0.75rem;
            align-items: flex-start;
        }

        .instruction-step:last-child {
            margin-bottom: 0;
        }

        .step-num {
            background: rgba(255, 168, 82, 0.15);
            border: 1px solid rgba(255, 168, 82, 0.3);
            color: #FFA852;
            width: 22px;
            height: 22px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: 800;
            flex-shrink: 0;
            margin-top: 2px;
        }

        .instruction-step p {
            font-size: 0.8rem;
            color: #A39081;
            line-height: 1.4;
            margin: 0;
        }

        .instruction-step strong {
            color: #FCF8F4;
        }

        /* ===========================
           UTILITY
        =========================== */
        .hidden { display: none !important; }
    </style>
</head>
<body>

<!-- ============================================================
     ERROR SCREEN (no valid session / revoked)
============================================================ -->
<?php if (!$helper): ?>
<div id="auth-error-screen">
    <div style="font-size: 3.5rem;">🔒</div>
    <h2 style="font-family:'Outfit',sans-serif; font-size:1.4rem; font-weight:800;">Access Required</h2>
    <p style="color:#8A7566; font-size:0.9rem; max-width:260px; line-height:1.5;">
        <?php echo htmlspecialchars($error ?: 'Please log in with your helper passcode to use the scanner.'); ?>
    </p>
    <a href="helper_scanner.php"
       style="margin-top:0.5rem; background:linear-gradient(135deg,#FFA852,#FF6B35); color:#130E0A; font-family:'Outfit',sans-serif; font-weight:800; font-size:1rem; padding:0.85rem 2.5rem; border-radius:50px; text-decoration:none; display:inline-flex; align-items:center; gap:0.5rem; box-shadow:0 4px 25px rgba(255,168,82,0.4);">
        🔑 Go to Login
    </a>
</div>
<?php else: ?>

<!-- ============================================================
     MAIN SCANNER INTERFACE
============================================================ -->

<!-- Background camera viewport -->
<div id="camera-viewport">
    <div id="reader"></div>
</div>

<!-- Scanning HUD -->
<div id="scan-hud">
    <div class="hud-vignette"></div>
    <div class="scan-box">
        <div class="corner corner-tl"></div>
        <div class="corner corner-tr"></div>
        <div class="corner corner-bl"></div>
        <div class="corner corner-br"></div>
        <div class="scan-laser"></div>
        <div class="scan-instruction">Align QR code inside the frame</div>
    </div>
</div>

<!-- Top status bar -->
<div class="top-bar">
    <div class="top-bar-brand">⚡ Event<span>Vibe</span></div>
    <div class="top-bar-right">
        <div class="helper-pill">
            🛡️ <span><?php echo htmlspecialchars($helper['name']); ?></span>
        </div>
        <a href="mobile_scanner.php?action=logout" class="logout-btn">✕</a>
    </div>
</div>

<!-- Camera offline placeholder -->
<div id="camera-offline">
    <div class="offline-icon">📷</div>
    <h2>Scanner Ready</h2>
    <p>Tap below to start your camera and begin scanning student QR codes.</p>
    <button id="start-camera-btn" onclick="startCamera()">
        <span>📡</span> Start Scanning
    </button>
</div>

<!-- Insecure Context warning screen -->
<div id="insecure-context-screen" class="hidden">
    <div class="warning-icon">🔒</div>
    <h2>Secure Context Required</h2>
    <p class="warning-desc">Mobile browsers block camera access over insecure HTTP connections on local networks.</p>
    
    <button id="switch-https-btn" onclick="switchToHttps()">
        <span>🔒</span> Switch to Secure HTTPS
    </button>
    
    <div class="instruction-box">
        <h3>How to enable camera access:</h3>
        <div class="instruction-step">
            <span class="step-num">1</span>
            <p>Tap <strong>Switch to Secure HTTPS</strong> above. Your browser will display a security warning (due to local self-signed SSL certificate).</p>
        </div>
        <div class="instruction-step">
            <span class="step-num">2</span>
            <p>Tap <strong>Advanced</strong> or <strong>Show Details</strong>, then select <strong>Proceed to site (unsafe)</strong> or <strong>Visit website</strong>.</p>
        </div>
        <div class="instruction-step">
            <span class="step-num">3</span>
            <p>Allow camera permissions when prompted. The scanner will start automatically.</p>
        </div>
    </div>
</div>

<!-- Bottom action tray -->
<div class="bottom-tray">
    <button class="cam-flip-btn" id="flip-cam-btn" onclick="flipCamera()" title="Switch Camera">🔄</button>
    <div class="scan-status-center">
        <span id="scan-status-text">Camera offline</span>
    </div>
    <button class="manual-btn" onclick="toggleManualDrawer()" title="Manual entry">⌨️</button>
</div>

<!-- Processing spinner overlay -->
<div id="processing-overlay">
    <div class="spinner"></div>
</div>

<!-- Result sheet backdrop -->
<div id="result-sheet-backdrop" onclick="dismissResult()"></div>

<!-- Result slide-up sheet -->
<div id="result-sheet">
    <div class="sheet-handle"></div>

    <div class="result-header">
        <div class="result-icon" id="result-icon-el">✅</div>
        <div class="result-title-block">
            <div class="result-action-label" id="result-action-label">Check-In</div>
            <div class="result-title" id="result-title-el">Access Granted</div>
        </div>
    </div>

    <!-- Student info grid (shown on success) -->
    <div id="result-info-grid" class="info-grid">
        <div class="info-cell full-width">
            <div class="info-cell-label">🎫 Event</div>
            <div class="info-cell-value" id="res-event">-</div>
        </div>
        <div class="info-cell">
            <div class="info-cell-label">👤 Student</div>
            <div class="info-cell-value" id="res-name">-</div>
        </div>
        <div class="info-cell">
            <div class="info-cell-label">🎓 Roll / Batch</div>
            <div class="info-cell-value" id="res-roll">-</div>
        </div>
        <div class="info-cell">
            <div class="info-cell-label">🏷️ Role</div>
            <div class="info-cell-value" id="res-role">-</div>
        </div>
        <div class="info-cell">
            <div class="info-cell-label">🍽️ Food</div>
            <div class="info-cell-value" id="res-food">-</div>
        </div>
    </div>

    <!-- Error detail (shown on failure) -->
    <div id="result-error-detail" class="error-detail hidden">
        <span id="result-error-text">-</span>
    </div>

    <!-- Timestamp -->
    <div class="timestamp-row" id="result-timestamp-row">
        <span class="timestamp-label">⏰ Scanned</span>
        <span class="timestamp-value" id="res-timestamp">-</span>
    </div>

    <!-- Auto-dismiss progress bar -->
    <div class="dismiss-bar-wrap">
        <div class="dismiss-bar" id="dismiss-bar"></div>
    </div>
</div>

<!-- Manual token entry drawer -->
<div id="manual-drawer">
    <div class="manual-drawer-title">Manual Token Entry</div>
    <div class="manual-input-row">
        <input type="text" id="manual-token" placeholder="Paste QR token..." autocomplete="off" spellcheck="false">
        <button id="manual-verify-btn" onclick="verifyManualToken()">Verify</button>
    </div>
</div>

<!-- ============================================================
     JAVASCRIPT
============================================================ -->
<script>
    const HELPER_KEY = <?php echo json_encode($helper_key); ?>;

    let html5QrCode = null;
    let isCameraActive = false;
    let isProcessing = false;
    let dismissTimer = null;
    let cameraFacingMode = 'environment'; // Start with back camera
    let allCameras = [];
    let currentCameraIndex = 0;
    let manualDrawerOpen = false;

    // ─── Camera Lifecycle ────────────────────────────────────

    async function startCamera() {
        document.getElementById('camera-offline').style.display = 'none';
        document.getElementById('scan-status-text').textContent = 'Starting camera…';

        if (!html5QrCode) {
            html5QrCode = new Html5Qrcode('reader');
        }

        // Try to enumerate cameras first for flip support
        try {
            allCameras = await Html5Qrcode.getCameras();
            // Find the back camera by default
            const backCam = allCameras.find(c => /back|rear|environment/i.test(c.label));
            if (backCam) {
                currentCameraIndex = allCameras.indexOf(backCam);
            }
        } catch (e) {
            console.warn('Could not enumerate cameras:', e);
        }

        const config = {
            fps: 15,
            qrbox: function(w, h) {
                const s = Math.min(w, h) * 0.65;
                return { width: s, height: s };
            },
            aspectRatio: window.innerHeight / window.innerWidth,
            disableFlip: false
        };

        // Use specific camera ID if available, else facingMode
        let cameraConstraint;
        if (allCameras.length > 0) {
            cameraConstraint = { deviceId: { exact: allCameras[currentCameraIndex].id } };
        } else {
            cameraConstraint = { facingMode: cameraFacingMode };
        }

        try {
            await html5QrCode.start(cameraConstraint, config, onScanSuccess, onScanFailure);
            isCameraActive = true;
            document.getElementById('scan-status-text').textContent = 'Scanning…';
        } catch (err) {
            console.error('Camera start failed:', err);
            document.getElementById('camera-offline').style.display = 'flex';
            document.getElementById('camera-offline').innerHTML = `
                <div class="offline-icon">⚠️</div>
                <h2 style="color:#F87171;">Camera Error</h2>
                <p style="color:#8A7566;">Could not access camera. Please allow camera permissions in your browser settings.</p>
                <button id="start-camera-btn" onclick="startCamera()" style="margin-top:0.5rem; background:linear-gradient(135deg,#FFA852,#FF6B35); border:none; border-radius:50px; padding:0.85rem 2.5rem; color:#130E0A; font-family:'Outfit',sans-serif; font-weight:800; font-size:1rem; cursor:pointer; display:flex; align-items:center; gap:0.6rem; box-shadow:0 4px 25px rgba(255,168,82,0.4);">
                    🔄 Retry
                </button>
            `;
            document.getElementById('scan-status-text').textContent = 'Camera offline';
        }
    }

    async function stopCamera() {
        if (html5QrCode && isCameraActive) {
            try {
                await html5QrCode.stop();
                isCameraActive = false;
            } catch (e) {
                console.warn('Stop camera error:', e);
            }
        }
    }

    async function flipCamera() {
        if (!isCameraActive) return;

        if (allCameras.length < 2) {
            // Toggle facingMode fallback
            cameraFacingMode = cameraFacingMode === 'environment' ? 'user' : 'environment';
        } else {
            currentCameraIndex = (currentCameraIndex + 1) % allCameras.length;
        }

        document.getElementById('scan-status-text').textContent = 'Switching camera…';
        await stopCamera();
        html5QrCode = null;
        await startCamera();
    }

    // ─── Scan Callbacks ──────────────────────────────────────

    function onScanSuccess(decodedText) {
        if (isProcessing) return;
        verifyToken(decodedText);
    }

    function onScanFailure(error) {
        // Silent — keep scanning
    }

    // ─── API Verification ────────────────────────────────────

    function verifyToken(token) {
        if (isProcessing) return;
        isProcessing = true;

        let finalToken = token;
        try {
            if (token.startsWith('http://') || token.startsWith('https://')) {
                const url = new URL(token);
                const urlToken = url.searchParams.get('token');
                if (urlToken) {
                    finalToken = urlToken;
                }
            }
        } catch (e) {
            console.error("Error parsing scanned URL:", e);
        }

        document.getElementById('processing-overlay').style.display = 'flex';

        fetch('scan_handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ token: finalToken, helper_key: HELPER_KEY })
        })
        .then(r => r.json())
        .then(data => {
            document.getElementById('processing-overlay').style.display = 'none';
            showResult(data);
        })
        .catch(err => {
            document.getElementById('processing-overlay').style.display = 'none';
            showResult({ success: false, message: '🌐 Connection error. Please check your network and try again.' });
        });
    }

    // ─── Result Display ──────────────────────────────────────

    function showResult(data) {
        const sheet = document.getElementById('result-sheet');
        const hud = document.getElementById('scan-hud');
        const backdrop = document.getElementById('result-sheet-backdrop');

        // Clear previous
        clearTimeout(dismissTimer);
        sheet.className = 'active';
        hud.className = '';

        // Determine type
        let type = 'error';
        if (data.success) {
            type = (data.type === 'exit') ? 'exit' : 'entry';
        }

        // Apply sheet type class
        sheet.classList.add('type-' + type);
        hud.classList.add(type === 'entry' ? 'success' : (type === 'exit' ? 'success' : 'error'));

        // Vibration feedback
        if ('vibrate' in navigator) {
            if (data.success) {
                navigator.vibrate([80, 60, 80]); // Two short pulses = success
            } else {
                navigator.vibrate(300); // Long pulse = error
            }
        }

        // Set icon and labels
        const icons = { entry: '✅', exit: '🔵', error: '❌' };
        const labels = { entry: 'Check-In Successful', exit: 'Check-Out Successful', error: 'Access Denied' };
        const titles = {
            entry: data.student_name || 'Verified',
            exit: data.student_name || 'Verified',
            error: 'Scan Failed'
        };

        document.getElementById('result-icon-el').textContent = icons[type];
        document.getElementById('result-action-label').textContent =
            type === 'entry' ? '🟢 ENTRY MARKED' : (type === 'exit' ? '🔵 EXIT MARKED' : '🔴 ACCESS DENIED');
        document.getElementById('result-title-el').textContent = titles[type];

        if (data.success) {
            // Show info grid
            document.getElementById('result-info-grid').classList.remove('hidden');
            document.getElementById('result-error-detail').classList.add('hidden');

            document.getElementById('res-event').textContent = data.event_title || '-';
            document.getElementById('res-name').textContent = data.student_name || '-';
            document.getElementById('res-roll').textContent = (data.roll_no || '-') + ' / ' + (data.batch || '-');
            document.getElementById('res-role').textContent = data.event_role || '-';
            document.getElementById('res-food').textContent = data.food_preference || '-';
            document.getElementById('res-timestamp').textContent = data.timestamp || new Date().toLocaleTimeString();
        } else {
            // Show error detail
            document.getElementById('result-info-grid').classList.add('hidden');
            document.getElementById('result-error-detail').classList.remove('hidden');
            document.getElementById('result-error-text').textContent = data.message || 'Unknown error occurred.';
            document.getElementById('res-timestamp').textContent = new Date().toLocaleTimeString();
        }

        // Show sheet and backdrop
        backdrop.classList.add('active');

        // Start dismiss timer
        const bar = document.getElementById('dismiss-bar');
        bar.classList.remove('running');
        void bar.offsetWidth; // Reflow trigger
        bar.classList.add('running');

        dismissTimer = setTimeout(() => {
            dismissResult();
        }, 4000);

        // Reset HUD feedback after 1.5s
        setTimeout(() => {
            hud.className = '';
        }, 1500);

        // Release processing lock after 2.5s (prevent double-scan)
        setTimeout(() => {
            isProcessing = false;
        }, 2500);
    }

    function dismissResult() {
        clearTimeout(dismissTimer);
        const sheet = document.getElementById('result-sheet');
        const backdrop = document.getElementById('result-sheet-backdrop');
        sheet.classList.remove('active');
        backdrop.classList.remove('active');
        // Remove type classes
        sheet.classList.remove('type-entry', 'type-exit', 'type-error');
    }

    // ─── Manual Entry Drawer ─────────────────────────────────

    function toggleManualDrawer() {
        const drawer = document.getElementById('manual-drawer');
        manualDrawerOpen = !manualDrawerOpen;
        if (manualDrawerOpen) {
            drawer.classList.add('open');
            document.getElementById('manual-token').focus();
        } else {
            drawer.classList.remove('open');
        }
    }

    function verifyManualToken() {
        const input = document.getElementById('manual-token');
        const val = input.value.trim();
        if (!val) { input.focus(); return; }
        input.value = '';
        toggleManualDrawer();
        verifyToken(val);
    }

    function checkSecureContext() {
        if (!window.isSecureContext) {
            document.getElementById('insecure-context-screen').classList.remove('hidden');
            // Hide bottom tray controls to clean up UI
            const tray = document.querySelector('.bottom-tray');
            if (tray) tray.style.display = 'none';
            // Hide offline view
            document.getElementById('camera-offline').style.display = 'none';
            return false;
        }
        return true;
    }

    function switchToHttps() {
        const httpsUrl = 'https://' + window.location.host + window.location.pathname + window.location.search;
        window.location.href = httpsUrl;
    }

    // Submit manual on Enter key
    document.addEventListener('DOMContentLoaded', () => {
        document.getElementById('manual-token').addEventListener('keydown', (e) => {
            if (e.key === 'Enter') verifyManualToken();
        });

        if (checkSecureContext()) {
            // Auto-start camera after a short delay
            setTimeout(() => {
                startCamera();
            }, 600);
        }
    });

    // Swipe down on result sheet to dismiss
    let touchStartY = 0;
    document.getElementById('result-sheet').addEventListener('touchstart', (e) => {
        touchStartY = e.touches[0].clientY;
    }, { passive: true });
    document.getElementById('result-sheet').addEventListener('touchmove', (e) => {
        const dy = e.touches[0].clientY - touchStartY;
        if (dy > 60) {
            dismissResult();
        }
    }, { passive: true });
</script>

<?php endif; ?>
</body>
</html>

<?php
/**
 * security.php — Nexus Security Helper
 * Provides CSRF token generation/validation and AES-256-CBC encryption functions.
 * Include this file in any page that has forms or handles sensitive data.
 */

// Ensure session is started (safe to call multiple times)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!file_exists(__DIR__ . '/config.php')) {
    die("Error: config.php is missing. Please copy config.example.php to config.php and fill in your details.");
}
require_once __DIR__ . '/config.php';

// ─── AES-256 Encryption Key ────────────────────────────────────────────────
// This is the secret key used to encrypt/decrypt sensitive student data.
// KEEP THIS SECRET — never expose it publicly.
// Note: AES_SECRET_KEY is now defined in config.php

/**
 * Encrypt a plain-text string using AES-256-CBC.
 * Returns a base64-encoded string: IV + encrypted data.
 */
function encryptData(string $plaintext): string {
    if (empty($plaintext)) return $plaintext;
    $iv     = random_bytes(16); // 128-bit random IV
    $cipher = openssl_encrypt($plaintext, 'AES-256-CBC', AES_SECRET_KEY, OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $cipher);
}

/**
 * Decrypt a base64-encoded AES-256-CBC encrypted string.
 * Returns the original plain-text string.
 */
function decryptData(string $encrypted): string {
    if (empty($encrypted)) return $encrypted;
    $decoded = base64_decode($encrypted);
    if ($decoded === false || strlen($decoded) < 17) return $encrypted; // not encrypted / corrupted
    $iv     = substr($decoded, 0, 16);
    $cipher = substr($decoded, 16);
    $plain  = openssl_decrypt($cipher, 'AES-256-CBC', AES_SECRET_KEY, OPENSSL_RAW_DATA, $iv);
    return $plain !== false ? $plain : $encrypted;
}


/**
 * Generate a new CSRF token — disabled, returns empty string.
 */
function generateCsrfToken(): string {
    return '';
}

/**
 * Validate the CSRF token — disabled, always passes.
 */
function validateCsrfToken(): void {
    // CSRF validation disabled
}

/**
 * Output a hidden CSRF input field — disabled, outputs nothing.
 */
function csrfField(): void {
    // CSRF field disabled
}

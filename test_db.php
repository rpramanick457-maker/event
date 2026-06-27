<?php
// test_db.php
// Temporary debug file to verify environment variables on Vercel

require_once __DIR__ . '/config_loader.php';

header('Content-Type: text/html; charset=utf-8');

echo "<h2>Database Connection Debugger</h2>";

echo "<h3>Configured Constants:</h3>";
echo "<ul>";
echo "<li><strong>DB_HOST:</strong> " . htmlspecialchars(DB_HOST) . " (Length: " . strlen(DB_HOST) . ")</li>";
echo "<li><strong>DB_PORT:</strong> " . htmlspecialchars(DB_PORT) . "</li>";
echo "<li><strong>DB_USER:</strong> " . htmlspecialchars(DB_USER) . " (Length: " . strlen(DB_USER) . ")</li>";
echo "<li><strong>DB_NAME:</strong> " . htmlspecialchars(DB_NAME) . " (Length: " . strlen(DB_NAME) . ")</li>";
echo "<li><strong>DB_PASS Length:</strong> " . strlen(DB_PASS) . "</li>";

if (strlen(DB_PASS) > 0) {
    echo "<li><strong>DB_PASS preview:</strong> " . htmlspecialchars(substr(DB_PASS, 0, 2)) . "..." . htmlspecialchars(substr(DB_PASS, -2)) . "</li>";
} else {
    echo "<li><strong>DB_PASS is EMPTY</strong></li>";
}
echo "</ul>";

echo "<h3>Attempting Connection:</h3>";
try {
    $conn = new PDO("mysql:host=" . DB_HOST . ";port=" . DB_PORT . ";dbname=" . DB_NAME, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        1000 => true
    ]);
    echo "<span style='color:green; font-weight:bold;'>✔ SUCCESS: Connected to the database!</span>";
} catch (PDOException $e) {
    echo "<span style='color:red; font-weight:bold;'>✘ FAILED: " . htmlspecialchars($e->getMessage()) . "</span>";
}

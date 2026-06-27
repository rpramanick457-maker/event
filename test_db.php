<?php
// test_db.php
// Diagnostic tool to check the exact length and hash of the environment variables in Vercel

header('Content-Type: text/plain; charset=utf-8');

$host = getenv('DB_HOST');
$dbname = getenv('DB_NAME');
$user = getenv('DB_USER');
$pass = getenv('DB_PASS');

echo "=== ENVIRONMENT VARIABLES DIAGNOSTICS ===\n";
echo "DB_HOST: " . ($host ?: 'NOT SET') . " (Length: " . strlen($host) . ")\n";
echo "DB_NAME: " . ($dbname ?: 'NOT SET') . " (Length: " . strlen($dbname) . ")\n";
echo "DB_USER: " . ($user ?: 'NOT SET') . " (Length: " . strlen($user) . ")\n";
echo "DB_PASS Length: " . strlen($pass) . "\n";
if ($pass) {
    echo "DB_PASS Hash (sha256): " . hash('sha256', $pass) . "\n";
}

echo "\n=== CONNECTION ATTEMPT ===\n";
try {
    $conn = new PDO("mysql:host=$host;port=3306;dbname=$dbname", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 2
    ]);
    echo "✔ SUCCESS: Connected successfully!\n";
} catch (PDOException $e) {
    echo "✘ FAILED: " . $e->getMessage() . "\n";
}

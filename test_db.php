<?php
// test_db.php
// Diagnostic tool to check if connection to host succeeds without selecting a database

header('Content-Type: text/plain; charset=utf-8');

$host = "bvqic99mcyc1wpnsxcmo-mysql.services.clever-cloud.com";
$user = "utuqkgdc0qznvfok";
$pass = "qzw6G8CFofVlq5ClAefl"; // Let's check with the default spelling

echo "Attempting connection to HOST only (without selecting database)...\n";
try {
    $conn = new PDO("mysql:host=$host;port=3306", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 2
    ]);
    echo "✔ SUCCESS: Connected to HOST successfully! (The user/pass is correct, the DB name might be the issue)\n";
    
    // Let's query the available databases
    $stmt = $conn->query("SHOW DATABASES");
    $dbs = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Available databases: " . implode(', ', $dbs) . "\n";
    
} catch (PDOException $e) {
    echo "✘ FAILED: " . $e->getMessage() . "\n";
}

<?php
// test_db.php
// Extremely fast diagnostic checking only 6 most likely variations

header('Content-Type: text/plain; charset=utf-8');

$host = "bvqic99mcyc1wpnsxcmo-mysql.services.clever-cloud.com";
$dbname = "bvqic99mcyc1wpnsxcmo"; // Correct DB name with '1'
$user = "utuqkgdc0qznvfok";

// The 6 most likely password candidates
$candidates = [
    "qzw6G8CFofVlq5ClAefl", // Candidate 1 (all lowercase L)
    "qzw6G8CFofV1q5C1Aef1", // Candidate 2 (all digit 1)
    "qzw6G8CFofVIq5CIAefI", // Candidate 3 (all uppercase I)
    "qzw6G8CFofV1q5ClAefl", // Candidate 4 (first is 1, rest L)
    "qzw6G8CFofVlq5C1Aefl", // Candidate 5 (middle is 1, rest L)
    "qzw6G8CFofVlq5ClAef1"  // Candidate 6 (last is 1, rest L)
];

echo "Starting rapid Candidate test (6 passwords)...\n";

foreach ($candidates as $pass) {
    echo "Testing: $pass\n";
    try {
        $conn = new PDO("mysql:host=$host;port=3306;dbname=$dbname", $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 2
        ]);
        echo "✔ SUCCESS! PASSWORD IS: $pass\n";
        exit;
    } catch (PDOException $e) {
        echo "✘ Failed for: $pass - " . $e->getMessage() . "\n";
    }
}

echo "None of the 6 candidates worked.\n";

<?php
// test_db.php
// Highly optimized visual brute-force connection tool running on Vercel
// Checks 27 combinations for the database name 'bvqic99mcyclwpnsxcmo' (with 'l')

header('Content-Type: text/plain; charset=utf-8');

$host = "bvqic99mcyc1wpnsxcmo-mysql.services.clever-cloud.com";
$dbname = "bvqic99mcyclwpnsxcmo"; // Database Name with 'l'
$user = "utuqkgdc0qznvfok";

// Vertical lines (l1, l2, l3) in "qzw6G8CFofV [l1] q5C [l2] Aef [l3]"
$l_options = ['l', '1', 'I'];

echo "Starting optimized brute force (27 combinations with DB_NAME containing 'l')...\n";

$count = 0;
foreach ($l_options as $l1) {
    foreach ($l_options as $l2) {
        foreach ($l_options as $l3) {
            $count++;
            $pass = "qzw6G8CFofV" . $l1 . "q5C" . $l2 . "Aef" . $l3;
            try {
                $conn = new PDO("mysql:host=$host;port=3306;dbname=$dbname", $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 1
                ]);
                echo "SUCCESS!\n";
                echo "PASSWORD: $pass\n";
                exit;
            } catch (PDOException $e) {
                // Fail and continue
            }
        }
    }
}

echo "Finished 27 combinations. No password worked.\n";

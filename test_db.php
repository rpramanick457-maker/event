<?php
// test_db.php
// Diagnostic brute-force checking user variations and password variations on Vercel

header('Content-Type: text/plain; charset=utf-8');

$host = "bvqic99mcyc1wpnsxcmo-mysql.services.clever-cloud.com";

// Try both usernames: 'utuqkgdc0qznvfok' (with 0) and 'utuqkgdcoqznvfok' (with o)
$users = ["utuqkgdc0qznvfok", "utuqkgdcoqznvfok"];

// Try both database names just in case
$dbnames = ["bvqic99mcyc1wpnsxcmo", "bvqic99mcyclwpnsxcmo"];

// Vertical lines (l1, l2, l3) in "qzw6G8CFofV [l1] q5C [l2] Aef [l3]"
$l_options = ['l', '1', 'I'];

echo "Starting user & password diagnostic brute force (108 combinations)...\n";

$count = 0;
foreach ($users as $user) {
    foreach ($dbnames as $dbname) {
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
                        echo "USER: $user\n";
                        echo "DB_NAME: $dbname\n";
                        echo "PASSWORD: $pass\n";
                        exit;
                    } catch (PDOException $e) {
                        // Fail and continue
                    }
                }
            }
        }
    }
}

echo "Finished 108 combinations. No credentials worked.\n";

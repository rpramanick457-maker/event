<?php
// test_db.php
// Visual brute-force connection tool running on Vercel to find the correct password

header('Content-Type: text/plain; charset=utf-8');

$host = "bvqic99mcyc1wpnsxcmo-mysql.services.clever-cloud.com";
$user = "utuqkgdc0qznvfok";

// We will try both database names: 'bvqic99mcyc1wpnsxcmo' (with 1) and 'bvqic99mcyclwpnsxcmo' (with l)
$dbnames = ["bvqic99mcyc1wpnsxcmo", "bvqic99mcyclwpnsxcmo"];

$q_options = ['q', 'g'];
$l_options = ['l', '1', 'I'];
$o_options = ['o', '0', 'O'];

echo "Starting brute force of 648 combinations...\n";

$count = 0;
foreach ($dbnames as $dbname) {
    foreach ($q_options as $q1) {
        foreach ($q_options as $q2) {
            foreach ($l_options as $l1) {
                foreach ($l_options as $l2) {
                    foreach ($l_options as $l3) {
                        foreach ($o_options as $o) {
                            $count++;
                            $pass = $q1 . "zw6G8CF" . $o . "fV" . $l1 . $q2 . "5C" . $l2 . "Aef" . $l3;
                            try {
                                $conn = new PDO("mysql:host=$host;port=3306;dbname=$dbname", $user, $pass, [
                                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                                ]);
                                echo "SUCCESS!\n";
                                echo "DB_NAME: $dbname\n";
                                echo "PASSWORD: $pass\n";
                                exit;
                            } catch (PDOException $e) {
                                // Ignore access denied, proceed
                            }
                        }
                    }
                }
            }
        }
    }
}

echo "Brute force finished. Checked $count combinations. No password worked.\n";

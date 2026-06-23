<?php
// certificate_helper.php
// Helper functions for generating QR codes and PDF certificates.

require_once __DIR__ . '/vendor/autoload.php'; // Composer autoloader (ensure vendor exists)
require_once __DIR__ . '/db_connect.php';

use Dompdf\Dompdf;
use Dompdf\Options;
use chillerlan\QRCode\QRCode;
use chillerlan\QRCode\QROptions;
use chillerlan\QRCode\Output\QROutputInterface;

/**
 * Generate a QR code SVG file for given data.
 * @param string $data Data to encode in QR.
 * @param string $outputPath Full path where SVG will be saved.
 * @return bool Success status.
 */
function generateQrCode(string $data, string $outputPath): bool {
    // Ensure directory exists
    $dir = dirname($outputPath);
    if (!file_exists($dir)) {
        mkdir($dir, 0777, true);
    }
    
    try {
        $options = new QROptions([
            'outputType' => QROutputInterface::MARKUP_SVG,
            'addQuietzone' => false,
        ]);
        
        $qrcode = new QRCode($options);
        $qrcode->render($data, $outputPath);
        return file_exists($outputPath);
    } catch (Exception $e) {
        return false;
    }
}

/**
 * Create a PDF certificate using HTML and Dompdf.
 * @param array $user User data (associative array with name, email, batch, role, etc.)
 * @param array $event Event data (title, date, location, etc.)
 * @param string $qrPath Path to QR SVG image.
 * @param string $templateUrl URL or path to Canva template image (background).
 * @param string $certId Unique Certificate ID.
 * @return string|false Path to generated PDF or false on failure.
 */
function createCertificatePdf(array $user, array $event, string $qrLocalPath, string $templateLocalPath, string $certId): string|false {
    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);
    $dompdf = new Dompdf($options);

    // Format date beautifully
    $eventDateFormatted = date('jS F, Y', strtotime($event['event_date']));

    // Convert template background image to base64
    $templateSrc = '';
    if (file_exists($templateLocalPath)) {
        $templateData = base64_encode(file_get_contents($templateLocalPath));
        $ext = strtolower(pathinfo($templateLocalPath, PATHINFO_EXTENSION));
        $mime = 'image/png';
        if ($ext === 'jpg' || $ext === 'jpeg') {
            $mime = 'image/jpeg';
        }
        $templateSrc = 'data:' . $mime . ';base64,' . $templateData;
    }

    // Convert QR SVG to base64
    $qrSrc = '';
    if (file_exists($qrLocalPath)) {
        $qrData = base64_encode(file_get_contents($qrLocalPath));
        $qrSrc = 'data:image/svg+xml;base64,' . $qrData;
    }

    // Build HTML. Use the Canva template as a background image.
    // We import Google Fonts to make the text beautiful.
    $html = '<html><head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <style>
        @import url("https://fonts.googleapis.com/css2?family=Montserrat:wght@700&family=Plus+Jakarta+Sans:wght@400;600;700&display=swap");
        
        @page {
            margin: 0;
            size: 841.89pt 595.28pt; /* Native A4 landscape points */
        }
        html, body {
            margin: 0;
            padding: 0;
            font-family: "Plus Jakarta Sans", sans-serif;
            width: 841.89pt;
            height: 595.28pt;
            background-color: #ffffff;
            overflow: hidden;
        }
        .certificate-container {
            position: relative;
            width: 841.89pt;
            height: 595.28pt;
            margin: 0;
            padding: 0;
            overflow: hidden;
            box-sizing: border-box;
        }
        .bg-template {
            position: absolute;
            top: 0;
            left: 0;
            width: 841.89pt;
            height: 595.28pt;
            z-index: 1;
            display: block;
        }
        .overlay-content {
            position: absolute;
            top: 0;
            left: 0;
            width: 841.89pt;
            height: 595.28pt;
            z-index: 2;
        }
        .student-name {
            position: absolute;
            top: 278pt; /* Position overlay name precisely */
            left: 0;
            width: 841.89pt;
            text-align: center;
            font-family: "Montserrat", sans-serif;
            font-size: 34pt;
            font-weight: bold;
            color: #1e1b4b; /* Deep premium blue */
            text-transform: uppercase;
            letter-spacing: 1.5px;
        }
        .details-text {
            position: absolute;
            top: 334pt; /* Position overlay details */
            left: 127pt;
            width: 587pt;
            text-align: center;
            font-size: 14pt;
            line-height: 1.8;
            color: #374151; /* Dark gray */
        }
        .details-text strong {
            color: #030712; /* Rich black for highlights */
            font-weight: 700;
        }
        .qr-code {
            position: absolute;
            bottom: 65pt; /* Position overlay QR */
            left: 127pt;
            width: 57pt;
            height: 57pt;
        }
        .cert-id {
            position: absolute;
            bottom: 45pt;
            left: 127pt;
            font-family: monospace;
            font-size: 9pt;
            font-weight: bold;
            color: #4b5563;
        }
    </style>
</head><body>
    <div class="certificate-container">
        ' . ($templateSrc ? '<img class="bg-template" src="' . $templateSrc . '" />' : '') . '
        <div class="overlay-content">
            <div class="student-name">' . htmlspecialchars($user['name']) . '</div>
            <div class="details-text">
                of <strong>' . htmlspecialchars($user['batch']) . '</strong> for <strong>' . htmlspecialchars($user['role']) . '</strong> committee in the <strong>' . htmlspecialchars($event['title']) . '</strong> on <strong>' . $eventDateFormatted . '</strong> organized by <strong>' . htmlspecialchars($event['location']) . '</strong>.
            </div>
            ' . ($qrSrc ? '<img class="qr-code" src="' . $qrSrc . '" alt="QR Code">' : '') . '
            <div class="cert-id">ID: ' . htmlspecialchars($certId) . '</div>
        </div>
    </div>
</body></html>';

    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'landscape');
    $dompdf->render();

    $pdfContent = $dompdf->output();
    $pdfDir = __DIR__ . '/uploads/certificates';
    if (!file_exists($pdfDir)) {
        mkdir($pdfDir, 0777, true);
    }
    $filename = 'certificate_' . $user['id'] . '_' . ($event['id'] ?? 'evt') . '_' . time() . '.pdf';
    $pdfPath = $pdfDir . '/' . $filename;
    file_put_contents($pdfPath, $pdfContent);
    return $pdfPath;
}

/**
 * Store certificate record in database, replacing existing record if found.
 */
function storeCertificate(int $userId, int $eventId, string $pdfPath, string $qrPath): bool {
    try {
        // Get DB connection (assuming global $conn or matching helper)
        global $conn;
        if (!isset($conn)) {
            $db_stmt = "SELECT 1"; // check or initialize
            // fallback if $conn isn't available
            // but db_connect.php initializes global $conn, so it should be available.
        }
        
        // Let's use the active $conn connection
        // Check if certificate already exists
        $stmt = $conn->prepare('SELECT id, pdf_path, qr_path FROM certificates WHERE user_id = ? AND event_id = ?');
        $stmt->execute([$userId, $eventId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            // Delete old files from disk
            if (file_exists($existing['pdf_path'])) {
                @unlink($existing['pdf_path']);
            }
            if (file_exists($existing['qr_path'])) {
                @unlink($existing['qr_path']);
            }
            // Update existing row
            $stmt_update = $conn->prepare('UPDATE certificates SET pdf_path = ?, qr_path = ?, created_at = CURRENT_TIMESTAMP WHERE id = ?');
            return $stmt_update->execute([$pdfPath, $qrPath, $existing['id']]);
        } else {
            // Insert new row
            $stmt_insert = $conn->prepare('INSERT INTO certificates (user_id, event_id, pdf_path, qr_path) VALUES (?, ?, ?, ?)');
            return $stmt_insert->execute([$userId, $eventId, $pdfPath, $qrPath]);
        }
    } catch (Exception $e) {
        return false;
    }
}
?>

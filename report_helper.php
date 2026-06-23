<?php
// report_helper.php
// Helper functions for exporting and archiving registrations as Excel (CSV) and PDF.

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/security.php';

/**
 * Generate an Excel-compatible CSV report with UTF-8 BOM.
 */
function generateExcelReport(array $registrations, string $eventTitle, string $outputPath): bool {
    $dir = dirname($outputPath);
    if (!file_exists($dir)) {
        mkdir($dir, 0777, true);
    }
    
    $fp = fopen($outputPath, 'w');
    if (!$fp) {
        throw new Exception("Unable to create report file at: " . $outputPath);
    }
    
    // Add UTF-8 BOM for proper Excel display of special characters
    fwrite($fp, "\xEF\xBB\xBF");
    
    // Add CSV Headers
    fputcsv($fp, ['Name', 'Roll', 'Stream', 'Batch']);
    
    // Populate rows (decrypt sensitive data)
    foreach ($registrations as $reg) {
        $name = decryptData($reg['student_name']);
        $roll = decryptData($reg['roll_no']);
        $stream = decryptData($reg['stream']);
        $batch = decryptData($reg['batch']);
        fputcsv($fp, [$name, $roll, $stream, $batch]);
    }
    
    fclose($fp);
    return file_exists($outputPath);
}

/**
 * Generate a beautifully styled PDF report of the registration batch using Dompdf.
 */
function generatePdfReport(array $registrations, string $eventTitle, string $outputPath): bool {
    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);
    $dompdf = new \Dompdf\Dompdf($options);
    
    $date = date('jS F, Y \a\t g:i A');
    
    $rowsHtml = '';
    $i = 1;
    foreach ($registrations as $reg) {
        $name = htmlspecialchars(decryptData($reg['student_name']));
        $roll = htmlspecialchars(decryptData($reg['roll_no']));
        $stream = htmlspecialchars(decryptData($reg['stream']));
        $batch = htmlspecialchars(decryptData($reg['batch']));
        
        $rowBg = ($i % 2 === 0) ? '#f8fafc' : '#ffffff';
        
        $rowsHtml .= "
        <tr style='background-color: {$rowBg};'>
            <td style='padding: 10px 12px; border-bottom: 1px solid #e2e8f0; text-align: center; color: #64748b; font-size: 11px;'>{$i}</td>
            <td style='padding: 10px 12px; border-bottom: 1px solid #e2e8f0; font-weight: 600; color: #0f172a; font-size: 12px;'>{$name}</td>
            <td style='padding: 10px 12px; border-bottom: 1px solid #e2e8f0; font-family: monospace; color: #334155; font-size: 12px;'>{$roll}</td>
            <td style='padding: 10px 12px; border-bottom: 1px solid #e2e8f0; color: #475569; font-size: 12px;'>{$stream}</td>
            <td style='padding: 10px 12px; border-bottom: 1px solid #e2e8f0; color: #475569; font-size: 12px;'>{$batch}</td>
        </tr>";
        $i++;
    }
    
    $html = "
    <html>
    <head>
        <meta http-equiv='Content-Type' content='text/html; charset=utf-8'/>
        <style>
            @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700&display=swap');
            @page {
                margin: 40px;
                size: A4 portrait;
            }
            body {
                font-family: 'Plus Jakarta Sans', sans-serif;
                color: #1e293b;
                margin: 0;
                padding: 0;
            }
            .header {
                border-bottom: 2px solid #6366f1;
                padding-bottom: 15px;
                margin-bottom: 25px;
            }
            .title {
                font-size: 20px;
                font-weight: 700;
                color: #0f172a;
                margin: 0 0 5px 0;
                text-transform: uppercase;
                letter-spacing: 0.5px;
            }
            .subtitle {
                font-size: 12px;
                color: #64748b;
                margin: 0;
            }
            .meta-info {
                float: right;
                text-align: right;
                font-size: 11px;
                color: #64748b;
                margin-top: -35px;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 10px;
            }
            th {
                background-color: #6366f1;
                color: #ffffff;
                text-align: left;
                padding: 12px;
                font-size: 11px;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                font-weight: 700;
            }
            th:first-child {
                border-top-left-radius: 6px;
                text-align: center;
            }
            th:last-child {
                border-top-right-radius: 6px;
            }
            .footer {
                position: fixed;
                bottom: 0;
                left: 0;
                right: 0;
                text-align: center;
                font-size: 10px;
                color: #94a3b8;
                border-top: 1px solid #f1f5f9;
                padding-top: 10px;
            }
        </style>
    </head>
    <body>
        <div class='header'>
            <h1 class='title'>Registration Report</h1>
            <p class='subtitle'>Event: " . htmlspecialchars($eventTitle) . "</p>
            <div class='meta-info'>
                <strong>Generated:</strong> {$date}<br>
                <strong>Total Registrations:</strong> " . count($registrations) . "
            </div>
        </div>
        
        <table>
            <thead>
                <tr>
                    <th style='width: 8%; text-align: center;'>#</th>
                    <th style='width: 32%;'>Name</th>
                    <th style='width: 25%;'>Roll</th>
                    <th style='width: 18%;'>Stream</th>
                    <th style='width: 17%;'>Batch</th>
                </tr>
            </thead>
            <tbody>
                {$rowsHtml}
            </tbody>
        </table>
        
        <div class='footer'>
            Nexus Event Management System &bull; Secure Report System
        </div>
    </body>
    </html>";
    
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();
    
    $pdfContent = $dompdf->output();
    
    $dir = dirname($outputPath);
    if (!file_exists($dir)) {
        mkdir($dir, 0777, true);
    }
    
    file_put_contents($outputPath, $pdfContent);
    return file_exists($outputPath);
}

/**
 * Archive current un-report-batched registrations into a new report batch.
 */
function archiveEventRegistrationBatch($conn, int $eventId): int|bool {
    // 1. Fetch event title
    $stmt_ev = $conn->prepare("SELECT title FROM events WHERE id = ?");
    $stmt_ev->execute([$eventId]);
    $eventTitle = $stmt_ev->fetchColumn();
    if (!$eventTitle) {
        return false;
    }
    
    // 2. Fetch registrations where report_id IS NULL
    $stmt_regs = $conn->prepare("SELECT * FROM registrations WHERE event_id = ? AND report_id IS NULL");
    $stmt_regs->execute([$eventId]);
    $registrations = $stmt_regs->fetchAll(PDO::FETCH_ASSOC);
    
    $count = count($registrations);
    if ($count === 0) {
        logSystemMessage($conn, "No new registrations to archive when closing registration for event '$eventTitle'.", "info");
        return false;
    }
    
    // 3. Create paths
    $reportsDir = __DIR__ . '/uploads/reports';
    if (!file_exists($reportsDir)) {
        mkdir($reportsDir, 0777, true);
    }
    
    // Generate secure randomized strings to make prediction impossible
    $hash = bin2hex(random_bytes(8));
    $timestamp = date('Y-m-d_H-i-s');
    
    $excelRelative = 'uploads/reports/report_event_' . $eventId . '_' . $timestamp . '_' . $hash . '.csv';
    $pdfRelative = 'uploads/reports/report_event_' . $eventId . '_' . $timestamp . '_' . $hash . '.pdf';
    
    $excelAbsolute = __DIR__ . '/' . $excelRelative;
    $pdfAbsolute = __DIR__ . '/' . $pdfRelative;
    
    try {
        // 4. Generate report files
        generateExcelReport($registrations, $eventTitle, $excelAbsolute);
        generatePdfReport($registrations, $eventTitle, $pdfAbsolute);
        
        // 5. Insert report record
        $stmt_insert = $conn->prepare("INSERT INTO event_reports (event_id, excel_path, pdf_path, registration_count) VALUES (?, ?, ?, ?)");
        $stmt_insert->execute([$eventId, $excelRelative, $pdfRelative, $count]);
        $reportId = $conn->lastInsertId();
        
        // 6. Associate registrations with the report batch
        $stmt_update = $conn->prepare("UPDATE registrations SET report_id = ? WHERE event_id = ? AND report_id IS NULL");
        $stmt_update->execute([$reportId, $eventId]);
        
        logSystemMessage($conn, "Generated registration report (Batch ID: #{$reportId}, Registrations: {$count}) for event '{$eventTitle}'.", "success");
        return $reportId;
    } catch (Exception $e) {
        logSystemMessage($conn, "Error archiving registration batch for event '{$eventTitle}': " . $e->getMessage(), "error");
        return false;
    }
}

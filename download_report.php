<?php
// download_report.php
// Secure download handler for event registration reports.
// Validates admin credentials before streaming requested file.

require_once __DIR__ . '/db_connect.php';

// Verify admin login status
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('HTTP/1.1 403 Forbidden');
    die("Access Denied: Admin authorization required.");
}

// Check inputs
$id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$type = isset($_GET['type']) ? trim($_GET['type']) : '';

if ($id <= 0 || !in_array($type, ['excel', 'pdf'], true)) {
    header('HTTP/1.1 400 Bad Request');
    die("Invalid request parameters.");
}

// Fetch report details
try {
    $stmt = $conn->prepare("
        SELECT er.*, e.title as event_title 
        FROM event_reports er 
        JOIN events e ON er.event_id = e.id 
        WHERE er.id = ?
    ");
    $stmt->execute([$id]);
    $report = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$report) {
        header('HTTP/1.1 404 Not Found');
        die("Report batch #$id not found in database.");
    }
    
    // Choose appropriate path and MIME type
    if ($type === 'excel') {
        $relativePath = $report['excel_path'];
        $mime = 'text/csv; charset=UTF-8';
        $ext = 'csv';
    } else {
        $relativePath = $report['pdf_path'];
        $mime = 'application/pdf';
        $ext = 'pdf';
    }
    
    $absolutePath = __DIR__ . '/' . $relativePath;
    
    // Clean event title for file name
    $safeTitle = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $report['event_title']);
    $dateStr = date('Y-m-d', strtotime($report['generated_at']));
    $downloadName = "{$safeTitle}_Report_{$dateStr}.{$ext}";
    
    if (!file_exists($absolutePath)) {
        header('HTTP/1.1 404 Not Found');
        die("Report file not found on server disk.");
    }
    
    // Output headers for file download
    header('Content-Description: File Transfer');
    header('Content-Type: ' . $mime);
    header('Content-Disposition: attachment; filename="' . $downloadName . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    header('Content-Length: ' . filesize($absolutePath));
    
    // Clear buffer to prevent corrupted file downloads
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    flush();
    
    readfile($absolutePath);
    exit();
} catch (Exception $e) {
    header('HTTP/1.1 500 Internal Server Error');
    die("Error occurred serving file: " . $e->getMessage());
}

<?php
/**
 * Secure Document Download & Preview Handler
 * PCC - Project Cost Control System
 */

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../config/database.php';

requireLogin();

$docId = intval($_GET['id'] ?? $_GET['doc_id'] ?? 0);
$preview = isset($_GET['preview']) && $_GET['preview'] == '1';

if ($docId <= 0) {
    header('HTTP/1.0 400 Bad Request');
    die('ID Dokumen tidak valid.');
}

$doc = dbGetRow("
    SELECT d.*, p.name as project_name 
    FROM project_documents d
    JOIN projects p ON d.project_id = p.id
    WHERE d.id = ?
", [$docId]);

if (!$doc) {
    header('HTTP/1.0 404 Not Found');
    die('Dokumen tidak ditemukan.');
}

// Access check
if (!canAccessProject($doc['project_id']) || !hasPermission('documentation.view')) {
    header('HTTP/1.0 403 Forbidden');
    die('Anda tidak memiliki hak akses untuk membuka atau mendownload dokumen ini.');
}

$uploadDir = __DIR__ . '/../../uploads/project_documents/';
$filepath = $uploadDir . $doc['filename'];

if (!file_exists($filepath)) {
    header('HTTP/1.0 404 Not Found');
    die('File fisik tidak ditemukan pada server.');
}

// Determine MIME type
$mimeType = $doc['file_type'];
if (empty($mimeType) || $mimeType === 'application/octet-stream') {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $detectedMime = finfo_file($finfo, $filepath);
    finfo_close($finfo);
    if ($detectedMime) {
        $mimeType = $detectedMime;
    } else {
        $ext = strtolower(pathinfo($doc['filename'], PATHINFO_EXTENSION));
        $mimeTypes = [
            'pdf' => 'application/pdf',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'ppt' => 'application/vnd.ms-powerpoint',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'zip' => 'application/zip',
            'rar' => 'application/x-rar-compressed',
            '7z' => 'application/x-7z-compressed',
            'dwg' => 'application/acad',
            'dxf' => 'application/dxf',
            'txt' => 'text/plain',
            'csv' => 'text/csv'
        ];
        $mimeType = $mimeTypes[$ext] ?? 'application/octet-stream';
    }
}

// Sanitize filename for header
$originalName = $doc['original_name'] ?: $doc['filename'];
$encodedName = rawurlencode($originalName);

// Clear output buffers
while (ob_get_level()) {
    ob_end_clean();
}

header('Content-Type: ' . $mimeType);
header('Content-Length: ' . filesize($filepath));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

if ($preview) {
    header("Content-Disposition: inline; filename=\"{$originalName}\"; filename*=UTF-8''{$encodedName}");
} else {
    header("Content-Disposition: attachment; filename=\"{$originalName}\"; filename*=UTF-8''{$encodedName}");
}

readfile($filepath);
exit;

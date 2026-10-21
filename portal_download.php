<?php
/**
 * Supplier contract download — serves ONLY the logged-in
 * supplier's own agreement scan. The file name comes from the
 * supplier's own agreement row, never from the request, so no
 * URL tampering can reach another company's contract.
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

requireLogin();
$u = currentUser();
if (($u['role'] ?? '') !== 'supplier' || empty($u['company_id'])) {
    http_response_code(403);
    exit('Not allowed.');
}
$pdo = db();
$st = $pdo->prepare('SELECT scan_file FROM agreements WHERE id = :id');
$st->execute([':id' => (int)$u['company_id']]);
$scan = $st->fetchColumn();
if (!$scan) {
    http_response_code(404);
    exit('No contract scan is on file for your company.');
}
$path = __DIR__ . '/uploads/' . basename((string)$scan);
if (!is_file($path)) {
    http_response_code(404);
    exit('The contract file is missing from the server.');
}
$mime = mime_content_type($path) ?: 'application/octet-stream';
header('Content-Type: ' . $mime);
header('Content-Disposition: attachment; filename="contract-' . basename((string)$scan) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
exit;

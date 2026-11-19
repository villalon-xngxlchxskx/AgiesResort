<?php
// Streams a payment receipt image to authorised staff. Files live outside the web root (storage/proofs).
require __DIR__ . '/../app/bootstrap.php';
require_perm('payments.view');
$p = q_one('SELECT proof_path FROM payments WHERE payment_id = ?', [int_in('id')]);
$rel = (string) ($p['proof_path'] ?? '');
if (!preg_match('#^proofs/([a-f0-9]{32})\.(jpg|png|webp)$#', $rel, $m) || !is_file(APP_ROOT . '/storage/' . $rel)) {
    http_response_code(404);
    exit('Receipt not found.');
}
$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
header('Content-Type: ' . $types[$m[2]]);
header('Content-Length: ' . filesize(APP_ROOT . '/storage/' . $rel));
header('Content-Disposition: inline; filename="receipt-' . (int) int_in('id') . '.' . $m[2] . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header("Content-Security-Policy: default-src 'none'; img-src 'self'");
readfile(APP_ROOT . '/storage/' . $rel);

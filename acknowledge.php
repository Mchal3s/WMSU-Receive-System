<?php
// ============================================================
// acknowledge.php
// Place at: /RecordSystem/acknowledge.php
//
// Handles two actions via ?action= param:
//   confirm  → just marks as received, shows confirmation page
//   download → marks as received AND streams the file download
// ============================================================
require_once __DIR__ . '/config/db.php';

$pdo    = getPDO();
$token  = trim($_GET['token']  ?? '');
$action = trim($_GET['action'] ?? 'confirm'); // 'confirm' or 'download'
$state  = 'invalid';
$info   = [];

if ($token) {
    $stmt = $pdo->prepare("
        SELECT
            dr.id, dr.status, dr.document_type,
            dr.document_ref, dr.document_id,
            r.name  AS receiver_name,
            r.email AS receiver_email
        FROM document_recipients dr
        JOIN receivers r ON r.id = dr.receiver_id
        WHERE dr.token = :token
        LIMIT 1
    ");
    $stmt->execute([':token' => $token]);
    $info = $stmt->fetch();

    if (!$info) {
        $state = 'invalid';
    } else {
        // Mark as released regardless of previous status
        // (idempotent — safe to call multiple times)
        if ($info['status'] !== 'released') {
            $pdo->prepare("
                UPDATE document_recipients
                SET status = 'released', released_at = NOW()
                WHERE token = :token
            ")->execute([':token' => $token]);
            $state = 'success';
        } else {
            $state = 'already';
        }

        // ── Handle download action ────────────────────────────
        if ($action === 'download') {
            // Fetch the first file linked to this document
            $file_stmt = $pdo->prepare("
                SELECT original_name, stored_name, file_path, mime_type
                FROM document_files
                WHERE document_type = :document_type
                  AND document_id   = :document_id
                ORDER BY uploaded_at ASC
                LIMIT 1
            ");
            $file_stmt->execute([
                ':document_type' => $info['document_type'],
                ':document_id'   => $info['document_id'],
            ]);
            $file = $file_stmt->fetch();

            if ($file) {
                $full_path = __DIR__ . '/' . $file['file_path'];

                if (file_exists($full_path)) {
                    // Stream the file as a download
                    header('Content-Description: File Transfer');
                    header('Content-Type: ' . ($file['mime_type'] ?: 'application/octet-stream'));
                    header('Content-Disposition: attachment; filename="' . addslashes($file['original_name']) . '"');
                    header('Content-Length: ' . filesize($full_path));
                    header('Cache-Control: must-revalidate');
                    header('Pragma: public');
                    ob_clean();
                    flush();
                    readfile($full_path);
                    exit;
                }
            }

            // File not found — fall through to confirmation page
        }
    }
}

$doc_label = ucwords(str_replace('_', ' ', $info['document_type'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document Acknowledgement — WMSU</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>body { font-family: 'IBM Plex Sans', sans-serif; }</style>
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center p-6">

    <div class="bg-white rounded-2xl shadow-lg p-10 w-full max-w-md text-center">

        <!-- Brand -->
        <div class="w-14 h-14 bg-red-900 rounded-full flex items-center justify-center mx-auto mb-4">
            <span class="text-white font-black text-xl">W</span>
        </div>
        <p class="text-xs text-gray-400 mb-8 uppercase tracking-widest">WMSU Document Management</p>

        <?php if ($state === 'success'): ?>
            <!-- Success -->
            <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-5">
                <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                </svg>
            </div>
            <h1 class="text-xl font-bold text-gray-800 mb-2">Document Received!</h1>
            <p class="text-gray-500 text-sm mb-6">
                Thank you, <strong><?= htmlspecialchars($info['receiver_name']) ?></strong>.<br>
                You have successfully acknowledged receipt of:
            </p>
            <div class="bg-gray-50 border border-gray-200 rounded-lg px-5 py-4 mb-6 text-left">
                <p class="text-xs text-gray-400 uppercase tracking-wider mb-1">Document Type</p>
                <p class="font-semibold text-gray-800"><?= htmlspecialchars($doc_label) ?></p>
                <?php if (!empty($info['document_ref'])): ?>
                <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars($info['document_ref']) ?></p>
                <?php endif; ?>
            </div>
            <p class="text-xs text-gray-400">
                This document has been marked as
                <span class="text-green-600 font-semibold">Released</span>
                in the records system.
            </p>

        <?php elseif ($state === 'already'): ?>
            <!-- Already acknowledged -->
            <div class="w-16 h-16 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-5">
                <svg class="w-8 h-8 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M13 16h-1v-4h-1m1-4h.01M12 2a10 10 0 100 20A10 10 0 0012 2z"/>
                </svg>
            </div>
            <h1 class="text-xl font-bold text-gray-800 mb-2">Already Acknowledged</h1>
            <p class="text-gray-500 text-sm mb-6">
                <strong><?= htmlspecialchars($info['receiver_name']) ?></strong>,
                you have already confirmed receipt of this document.
            </p>
            <div class="bg-gray-50 border border-gray-200 rounded-lg px-5 py-4 text-left">
                <p class="text-xs text-gray-400 uppercase tracking-wider mb-1">Document Type</p>
                <p class="font-semibold text-gray-800"><?= htmlspecialchars($doc_label) ?></p>
                <?php if (!empty($info['document_ref'])): ?>
                <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars($info['document_ref']) ?></p>
                <?php endif; ?>
            </div>

        <?php else: ?>
            <!-- Invalid -->
            <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-5">
                <svg class="w-8 h-8 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </div>
            <h1 class="text-xl font-bold text-gray-800 mb-2">Invalid Link</h1>
            <p class="text-gray-500 text-sm">
                This acknowledgement link is invalid or has expired.<br>
                Please contact the Records Office if you need assistance.
            </p>
        <?php endif; ?>

    </div>

</body>
</html>

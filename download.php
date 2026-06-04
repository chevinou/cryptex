<?php
// ============================================================
//  CRYPTEX — Téléchargement sécurisé d'une pièce jointe
//
//  GET /download.php?t=TOKEN&fid=FILE_ID
//
//  Contrôles :
//    1. Authentification SSO obligatoire
//    2. Le token doit appartenir à l'utilisateur connecté
//    3. Le secret ne doit pas être expiré / globalement détruit
//    4. Le fichier doit appartenir à ce secret
//  Note : on ne vérifie pas sr.is_destroyed pour les fichiers,
//  afin de permettre le téléchargement même après destroy_on_read
//  (le texte est détruit, mais les fichiers restent jusqu'à expiration).
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/crypto.php';

requireSSO();

$token = trim($_GET['t']   ?? '');
$fid   = (int)($_GET['fid'] ?? 0);

function abortDownload(string $msg, int $code = 403): never {
    http_response_code($code);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8">
          <title>Cryptex — Erreur</title>
          <link rel="stylesheet" href="assets/style.css"></head><body>
          <header class="site-header"><div class="logo-mark">🔐</div>
          <div><h1>CRYPTEX</h1></div></header>
          <main style="max-width:600px;">
          <div class="card"><div class="card-body success-box">
          <div class="big-icon">🚫</div><h2>Accès refusé</h2>
          <p style="color:#ef476f;">' . htmlspecialchars($msg) . '</p>
          <a href="index.php" class="btn btn-outline" style="margin-top:20px;">← Retour</a>
          </div></div></main></body></html>';
    exit;
}

// ── Validations ───────────────────────────────────────────────

if (empty($token) || $fid <= 0) {
    abortDownload('Paramètres manquants ou invalides.', 400);
}

$fileRow = getFileForDownload($fid, $token);

if (!$fileRow) {
    auditLog($token, 'file_access_denied', userEmail(), getClientIp());
    abortDownload('Ce fichier n\'existe plus, a expiré ou ne vous est pas destinataire.');
}

// Vérification que l'utilisateur connecté est bien le destinataire
$currentEmail   = strtolower(trim(userEmail()));
$recipientEmail = strtolower(trim($fileRow['recipient_email']));

if ($currentEmail !== $recipientEmail) {
    auditLog($token, 'file_access_denied_wrong_user', $currentEmail, getClientIp());
    abortDownload('Ce fichier ne vous est pas destiné. Connectez-vous avec le compte ' . $recipientEmail . '.');
}

// ── Déchiffrement et envoi ────────────────────────────────────

try {
    $plaintext = decryptStoredFile($fileRow['filename_stored'], $fileRow['file_nonce']);
} catch (Throwable $e) {
    auditLog($token, 'file_decrypt_error', $currentEmail, getClientIp());
    abortDownload('Impossible de déchiffrer le fichier. Contactez votre SI.', 500);
}

auditLog($token, 'file_downloaded', $currentEmail, getClientIp());

// Nom de fichier sûr pour le header Content-Disposition
$safeFilename = preg_replace('/[^a-zA-Z0-9_\-.]/', '_', $fileRow['filename_original']);
$mime         = $fileRow['mime_type'] ?: 'application/octet-stream';

// Forcer le téléchargement (pas d'inline) pour éviter XSS via SVG/HTML
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $safeFilename . '"');
header('Content-Length: ' . strlen($plaintext));
header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');

echo $plaintext;
exit;

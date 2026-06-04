<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/crypto.php';
require_once __DIR__ . '/includes/mail.php';

requireSSO();

$user    = currentUser();
$token   = trim($_GET['t'] ?? '');
$error   = null;
$secret  = null;
$content = null;
$files   = [];

if (empty($token)) {
    $error = 'Lien invalide ou manquant.';
} else {
    $secret = getSecretByToken($token);

    if (!$secret) {
        $error = 'Ce secret n\'existe plus, a expiré ou a déjà été consulté.';
        auditLog($token, 'access_failed_not_found', userEmail(), getClientIp());
    } else {
        $currentEmail   = strtolower(trim(userEmail()));
        $recipientEmail = strtolower(trim($secret['recipient_email']));

        if ($currentEmail !== $recipientEmail) {
            $error = 'Ce message ne vous est pas destiné. Connectez-vous avec le compte ' . $recipientEmail . '.';
            auditLog($token, 'access_denied_wrong_user', $currentEmail, getClientIp());
            $secret = null;
        } else {
            try {
                $content = decryptSecret($secret['content_encrypted'], $secret['nonce']);
                $files   = getSecretFiles((int)$secret['id']);
                auditLog($token, 'viewed', $currentEmail, getClientIp());
                markRead($token);
            } catch (Throwable $e) {
                $error  = 'Impossible de déchiffrer le message. Contactez votre SI.';
                auditLog($token, 'decrypt_error', $currentEmail, getClientIp());
                $secret = null;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Cryptex — Consultation sécurisée</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/style.css">
  <style>
    @media print { .secret-wrapper, .copy-btn { display:none!important } }

    /* ── Zone de dévoilement ── */
    .secret-wrapper {
      position: relative;
      border-radius: var(--radius-sm);
      overflow: hidden;
      margin: 20px 0;
    }
    .secret-box {
      background: #f0f4f8;
      border: 2px solid var(--primary-mid);
      border-radius: var(--radius-sm);
      padding: 20px;
    }
    .secret-content {
      font-family: 'Courier New', monospace;
      font-size: 15px;
      white-space: pre-wrap;
      word-break: break-all;
      color: var(--text);
      transition: filter .3s ease;
    }
    .secret-content.blurred {
      filter: blur(8px);
      user-select: none;
      pointer-events: none;
    }

    /* ── Overlay "Dévoiler" ── */
    .reveal-overlay {
      position: absolute;
      inset: 0;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 10px;
      background: rgba(240,244,248,.55);
      backdrop-filter: blur(2px);
      border-radius: var(--radius-sm);
      transition: opacity .3s ease;
    }
    .reveal-overlay.hidden {
      opacity: 0;
      pointer-events: none;
    }
    .reveal-overlay p {
      font-size: 13px;
      color: var(--text-muted);
      margin: 0;
    }

    .secret-actions {
      display: flex;
      align-items: center;
      gap: 10px;
      margin-top: 12px;
      flex-wrap: wrap;
    }
    .btn-hide {
      padding: 6px 14px;
      font-size: 12px;
      background: transparent;
      border: 2px solid var(--border);
      color: var(--text-muted);
      border-radius: 6px;
      cursor: pointer;
      font-family: inherit;
      transition: .15s;
    }
    .btn-hide:hover { border-color: var(--primary-mid); color: var(--primary-mid); }

    /* ── Pièces jointes ── */
    .attachments-section { margin-top: 24px; border-top: 1px solid var(--border); padding-top: 20px; }
    .attachments-section h3 { font-size: 14px; color: var(--text-muted); margin-bottom: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; }
    .file-download-item {
      display: flex; align-items: center; gap: 12px;
      background: #f8fafc; border: 1px solid var(--border);
      border-radius: 8px; padding: 10px 14px; margin-bottom: 8px;
    }
    .file-download-item .fdi-icon { font-size: 22px; flex-shrink: 0; }
    .file-download-item .fdi-info { flex: 1; min-width: 0; }
    .file-download-item .fdi-name { font-size: 13px; font-weight: 600; word-break: break-all; }
    .file-download-item .fdi-size { font-size: 11px; color: var(--text-muted); margin-top: 2px; }
  </style>
</head>
<body>

<div class="topbar">
  <div>
    <strong><?= htmlspecialchars(fullName()) ?></strong>
    <?php $ue = $user['email'] ?? ''; if ($ue): ?>&nbsp;·&nbsp;<?= htmlspecialchars($ue) ?><?php endif; ?>
  </div>
  <a href="logout.php">⎋ Déconnexion</a>
</div>

<header class="site-header">
  <div class="logo-mark">🔐</div>
  <div>
    <h1>CRYPTEX</h1>
    <div class="tagline">Consultation sécurisée · Auvergne Habitat</div>
  </div>
</header>

<main style="max-width:700px;">

  <?php if ($error): ?>
  <div class="card">
    <div class="card-body success-box">
      <div class="big-icon">🚫</div>
      <h2>Accès impossible</h2>
      <p style="color:#ef476f;"><?= htmlspecialchars($error) ?></p>
      <p style="font-size:13px;color:#999;margin-top:16px;">Contactez l'expéditeur ou votre SI.</p>
    </div>
  </div>

  <?php elseif ($content !== null): ?>

    <?php if ($secret['destroy_on_read']): ?>
    <div class="alert alert-warning">
      ⚡ Ce message a été <strong>détruit immédiatement</strong> après cette consultation. Il ne peut plus être relu.
    </div>
    <?php endif; ?>

  <div class="card">
    <div class="card-header">
      <span class="icon">🔓</span>
      <h2><?= htmlspecialchars($secret['title'] ?? 'Information confidentielle') ?></h2>
    </div>
    <div class="card-body">

      <div class="alert alert-info">
        🛡 Ne partagez pas cette information. Fermez la page une fois consultée.
      </div>

      <!-- Métadonnées -->
      <div class="secret-meta">
        <div class="meta-tag">👤 De : <strong><?= htmlspecialchars($secret['sender_name']) ?></strong></div>
        <div class="meta-tag">📅 Créé le : <?= formatDate($secret['created_at']) ?></div>
        <?php if (!$secret['destroy_on_read']): ?>
        <div class="meta-tag" id="countdown" data-expires="<?= htmlspecialchars($secret['expires_at']) ?>">⏱ Calcul…</div>
        <?php else: ?>
        <div class="meta-tag" style="background:#fff3cd;border-color:#ffe082;">⚡ Lecture unique</div>
        <?php endif; ?>
      </div>

      <!-- Zone secrète avec dévoilement -->
      <div class="secret-wrapper">

        <div class="secret-box">
          <pre class="secret-content blurred" id="secret-text"><?= htmlspecialchars($content) ?></pre>
        </div>

        <!-- Overlay par défaut -->
        <div class="reveal-overlay" id="reveal-overlay">
          <button class="btn btn-primary btn-lg" onclick="revealSecret()">
            👁 Dévoiler l'information
          </button>
          <p>Cliquez pour afficher le contenu confidentiel</p>
        </div>

      </div>

      <!-- Actions (visibles après dévoilement) -->
      <div class="secret-actions" id="secret-actions" style="display:none;">
        <button class="btn btn-outline copy-btn"
                style="font-size:13px;padding:8px 16px;"
                onclick="copyToClipboard(document.getElementById('secret-text').textContent, this)">
          📋 Copier
        </button>
        <button class="btn-hide" onclick="hideSecret()">
          🙈 Masquer à nouveau
        </button>
      </div>

      <div style="margin-top:24px;">
        <a href="index.php" class="btn btn-outline">← Retour à l'accueil</a>
      </div>

      <?php if (!empty($files)): ?>
      <div class="attachments-section">
        <h3>📎 Pièces jointes (<?= count($files) ?>)</h3>
        <?php foreach ($files as $f):
          $ext  = strtolower(pathinfo($f['filename_original'], PATHINFO_EXTENSION));
          $icons = ['pdf'=>'📄','jpg'=>'🖼','jpeg'=>'🖼','png'=>'🖼','gif'=>'🖼','webp'=>'🖼','svg'=>'🖼',
                    'doc'=>'📝','docx'=>'📝','xls'=>'📊','xlsx'=>'📊','ppt'=>'📊','pptx'=>'📊',
                    'zip'=>'🗜','gz'=>'🗜','7z'=>'🗜','tar'=>'🗜','txt'=>'📃','csv'=>'📃',
                    'json'=>'📃','xml'=>'📃','pem'=>'🔑','crt'=>'🔑','p12'=>'🔑','key'=>'🔑'];
          $icon = $icons[$ext] ?? '📎';
          $size = $f['file_size'];
          $sizeStr = $size < 1024 ? $size.' o' : ($size < 1048576 ? round($size/1024,1).' Ko' : round($size/1048576,1).' Mo');
        ?>
        <div class="file-download-item">
          <span class="fdi-icon"><?= $icon ?></span>
          <div class="fdi-info">
            <div class="fdi-name"><?= htmlspecialchars($f['filename_original']) ?></div>
            <div class="fdi-size"><?= $sizeStr ?></div>
          </div>
          <a href="download.php?t=<?= urlencode($token) ?>&amp;fid=<?= (int)$f['id'] ?>"
             class="btn btn-outline" style="font-size:12px;padding:6px 14px;white-space:nowrap;">
            ⬇ Télécharger
          </a>
        </div>
        <?php endforeach; ?>

        <?php if ($secret['destroy_on_read']): ?>
        <p style="font-size:12px;color:#e88c14;margin-top:8px;">
          ⚠ Ce secret est en lecture unique. Téléchargez les fichiers maintenant — ils seront supprimés à l'expiration du secret.
        </p>
        <?php endif; ?>
      </div>
      <?php endif; ?>

    </div>
  </div>

  <div class="alert alert-warning">
    🔒 <strong>Rappel :</strong> Ne communiquez jamais ces informations par email, Teams ou téléphone.
    <?php if (str_contains(strtolower($secret['title'] ?? ''), 'mot de passe') || str_contains(strtolower($secret['title'] ?? ''), 'password')): ?>
    Changez le mot de passe dès que possible.
    <?php endif; ?>
  </div>

  <?php endif; ?>

</main>

<footer>
  <strong>Cryptex</strong> — Consultation enregistrée dans le journal d'audit
</footer>

<script src="assets/app.js"></script>
<script>
function revealSecret() {
  document.getElementById('secret-text').classList.remove('blurred');
  document.getElementById('reveal-overlay').classList.add('hidden');
  document.getElementById('secret-actions').style.display = 'flex';
}

function hideSecret() {
  document.getElementById('secret-text').classList.add('blurred');
  document.getElementById('reveal-overlay').classList.remove('hidden');
  document.getElementById('secret-actions').style.display = 'none';
}
</script>

</body>
</html>

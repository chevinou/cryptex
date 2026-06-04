<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/mail.php';

requireSSO();

$flash = null;

// ── Actions POST ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $flash = ['type' => 'danger', 'msg' => 'Token CSRF invalide.'];
    } else {
        $action = $_POST['action'] ?? '';
        $token  = trim($_POST['token'] ?? '');

        if ($action === 'resend') {
            $secret = getSecretForResend($token, userEmail());
            if (!$secret) {
                $flash = ['type' => 'danger', 'msg' => 'Secret introuvable, expiré ou déjà consulté.'];
            } else {
                $sent = resendSecretLink([
                    'token'          => $secret['token'],
                    'recipient_email'=> $secret['recipient_email'],
                    'recipient_name' => $secret['recipient_name'] ?: 'Utilisateur',
                    'sender_name'    => $secret['sender_name'],
                    'title'          => $secret['title'] ?: 'Information confidentielle',
                    'expires_at'     => $secret['expires_at'],
                    'destroy_on_read'=> (int)$secret['destroy_on_read'],
                ]);
                auditLog($token, 'resent', userEmail(), getClientIp());
                $flash = $sent
                    ? ['type' => 'success', 'msg' => 'Lien renvoyé à ' . htmlspecialchars($secret['recipient_email']) . '.']
                    : ['type' => 'warning', 'msg' => 'Erreur lors de l\'envoi email — vérifiez la config SMTP.'];
            }

        } elseif ($action === 'delete') {
            if (deleteRecipientToken($token, userEmail())) {
                $flash = ['type' => 'success', 'msg' => 'Envoi supprimé. Le destinataire ne pourra plus accéder au lien.'];
            } else {
                $flash = ['type' => 'danger', 'msg' => 'Impossible de supprimer cet envoi (introuvable ou déjà consulté).'];
            }
        }
    }
}

$history = getSentHistory(userEmail(), activeOnly: true);
$csrf    = csrfToken();

function timeLeft(string $expiresAt): string {
    $diff = (new DateTime($expiresAt, new DateTimeZone('UTC')))->getTimestamp() - time();
    if ($diff <= 0) return 'Expiré';
    $d = floor($diff / 86400);
    $h = floor($diff / 3600);
    $m = floor(($diff % 3600) / 60);
    if ($d >= 1) return $d . ' jour' . ($d > 1 ? 's' : '');
    if ($h === 0) return '0h' . str_pad($m, 2, '0', STR_PAD_LEFT) . 'm'; // ← "0h59m"
    return $h . 'h' . str_pad($m, 2, '0', STR_PAD_LEFT) . 'm';           // ← "2h34m" partout
}
function fmtDate(string $d): string {
    return (new DateTime($d, new DateTimeZone('UTC')))
        ->setTimezone(new DateTimeZone('Europe/Paris'))
        ->format('d/m/Y H:i');
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Cryptex — Mes envois</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/style.css">
  <style>
    .secret-card { background:#fff; border-radius:var(--radius-sm); box-shadow:0 2px 12px rgba(30,58,95,.07); margin-bottom:16px; overflow:hidden; border:1px solid var(--border); }
    .secret-card-header { padding:14px 20px; background:#f8fafc; border-bottom:1px solid var(--border); display:flex; align-items:center; gap:12px; flex-wrap:wrap; }
    .secret-title { font-weight:700; font-size:15px; color:var(--primary); flex:1; }
    .secret-meta-inline { font-size:12px; color:var(--text-muted); display:flex; gap:14px; flex-wrap:wrap; }
    .recipient-row { display:flex; align-items:center; gap:12px; padding:12px 20px; border-bottom:1px solid #f0f4f8; flex-wrap:wrap; }
    .recipient-row:last-child { border-bottom:none; }
    .avatar-xs { width:32px; height:32px; border-radius:50%; flex-shrink:0; background:linear-gradient(135deg,var(--primary-mid),var(--accent)); display:flex; align-items:center; justify-content:center; color:#fff; font-size:11px; font-weight:700; }
    .recip-info { flex:1; min-width:150px; }
    .recip-name  { font-weight:600; font-size:13px; }
    .recip-email { font-size:11px; color:var(--text-muted); }
    .status-badge { display:inline-flex; align-items:center; gap:5px; padding:3px 10px; border-radius:12px; font-size:11px; font-weight:700; }
    .status-pending { background:#fff8e1; color:#92400e; }
    .status-read    { background:#d1f7ee; color:#065f46; }
    .expiry-tag { font-size:11px; color:var(--text-muted); background:#f0f4f8; padding:3px 8px; border-radius:8px; }
    .expiry-tag.urgent { background:#fde8ed; color:#9b1239; }
    .btn-action {
      padding:6px 12px; font-size:12px; font-weight:600;
      border-radius:6px; cursor:pointer; font-family:inherit;
      transition:.15s; white-space:nowrap; border:2px solid;
    }
    .btn-resend { background:#fff; border-color:var(--primary-mid); color:var(--primary-mid); }
    .btn-resend:hover { background:var(--primary-mid); color:#fff; }
    .btn-delete { background:#fff; border-color:var(--danger); color:var(--danger); }
    .btn-delete:hover { background:var(--danger); color:#fff; }
    .btn-copy { background:#fff; border-color:#6b7a90; color:#6b7a90; }
    .btn-copy:hover { background:#6b7a90; color:#fff; }
    .action-group { display:flex; gap:6px; }
    .empty-state { text-align:center; padding:60px 20px; color:var(--text-muted); }
    .empty-state .icon { font-size:48px; margin-bottom:16px; }
  </style>
</head>
<body>

<div class="topbar">
  <div>
    <strong><?= htmlspecialchars(fullName()) ?></strong>
    <?php $_svc = currentUser()['service'] ?? ''; if ($_svc): ?>&nbsp;·&nbsp;<?= htmlspecialchars($_svc) ?><?php endif; ?>
    <?php if (isAdmin()): ?>&nbsp;·&nbsp;<span style="color:#f9d879">👑 Admin</span><?php endif; ?>
  </div>
  <a href="logout.php">⎋ Déconnexion</a>
</div>

<header class="site-header">
  <div class="logo-mark">🔐</div>
  <div><h1>CRYPTEX</h1><div class="tagline">Mes envois actifs · Auvergne Habitat</div></div>
</header>

<nav class="nav-tabs">
  <a href="index.php">✉ Déposer</a>
  <a href="history.php" class="active">📋 Mes envois</a>
  <a href="inbox.php">📥 Mes réceptions</a>
  <?php if (isAdmin()): ?><a href="admin.php">👥 Utilisateurs</a><?php endif; ?>
</nav>

<main>

  <?php if ($flash): ?>
  <div class="alert alert-<?= $flash['type'] ?>"><?= htmlspecialchars($flash['msg']) ?></div>
  <?php endif; ?>

  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;">
    <div>
      <h2 style="font-size:18px;color:var(--primary);margin-bottom:4px;">
        Envois actifs
        <span style="font-size:14px;font-weight:400;color:var(--text-muted);margin-left:8px;">
          <?= count($history) ?> secret<?= count($history) > 1 ? 's' : '' ?>
        </span>
      </h2>
      <p style="font-size:13px;color:var(--text-muted);">Secrets non expirés et non consultés.</p>
    </div>
    <a href="index.php" class="btn btn-primary" style="flex-shrink:0;">+ Nouveau secret</a>
  </div>

  <?php if (empty($history)): ?>
  <div class="card">
    <div class="empty-state">
      <div class="icon">📭</div>
      <p style="font-size:16px;font-weight:600;color:var(--text);">Aucun envoi actif</p>
      <p style="font-size:14px;margin-top:8px;">Tous vos secrets ont été consultés ou sont expirés.</p>
      <a href="index.php" class="btn btn-primary" style="margin-top:20px;">✉ Déposer un secret</a>
    </div>
  </div>

  <?php else: ?>
  <?php foreach ($history as $s):
    $urgency = ((new DateTime($s['expires_at'], new DateTimeZone('UTC')))->getTimestamp() - time()) < 3600 * 4;
  ?>
  <div class="secret-card">

    <div class="secret-card-header">
      <div style="font-size:18px;">🔏</div>
      <div class="secret-title"><?= htmlspecialchars($s['title'] ?: 'Information confidentielle') ?></div>
      <div class="secret-meta-inline">
        <span>📅 Créé le <?= fmtDate($s['created_at']) ?></span>
        <span class="expiry-tag <?= $urgency ? 'urgent' : '' ?>">
          ⏱ <?= htmlspecialchars(timeLeft($s['expires_at'])) ?><?= $urgency ? ' ⚠' : '' ?>
        </span>
        <?php if ($s['destroy_on_read']): ?>
        <span class="expiry-tag">⚡ Lecture unique</span>
        <?php endif; ?>
        <span><?= count($s['recipients']) ?> destinataire<?= count($s['recipients']) > 1 ? 's' : '' ?></span>
      </div>
    </div>

    <?php foreach ($s['recipients'] as $r):
      $parts    = explode(' ', trim($r['name'] ?: $r['email']));
      $initials = strtoupper(substr($parts[0], 0, 1) . substr($parts[1] ?? '', 0, 1));
      $isRead   = !empty($r['read_at']);
    ?>
    <div class="recipient-row">

      <div class="avatar-xs"><?= $initials ?: '?' ?></div>

      <div class="recip-info">
        <div class="recip-name"><?= htmlspecialchars($r['name'] ?: '—') ?></div>
        <div class="recip-email"><?= htmlspecialchars($r['email']) ?></div>
      </div>

      <?php if ($isRead): ?>
      <span class="status-badge status-read">✅ Consulté le <?= fmtDate($r['read_at']) ?></span>
      <?php else: ?>
      <span class="status-badge status-pending">⏳ En attente</span>
      <?php endif; ?>

      <!-- Actions -->
      <div class="action-group">

        <?php if (!$isRead): ?>
        <!-- Renvoyer -->
        <form method="POST" style="margin:0;">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="resend">
          <input type="hidden" name="token"  value="<?= htmlspecialchars($r['token']) ?>">
          <button type="submit" class="btn-action btn-resend"
                  onclick="return confirm('Renvoyer le lien à <?= htmlspecialchars(addslashes($r['email'])) ?> ?')">
            📧 Renvoyer
          </button>
        </form>
        <!-- Copier le lien -->
        <button class="btn-action btn-copy"
                onclick="copyToClipboard('<?= APP_URL ?>/view.php?t=<?= urlencode($r['token']) ?>', this)"
                title="Copier le lien de consultation">
          📋 Copier le lien
        </button>
        <?php endif; ?>

        <!-- Supprimer -->
        <form method="POST" style="margin:0;">
          <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
          <input type="hidden" name="action" value="delete">
          <input type="hidden" name="token"  value="<?= htmlspecialchars($r['token']) ?>">
          <button type="submit" class="btn-action btn-delete"
                  onclick="return confirm('Supprimer cet envoi ?\n\nLe destinataire ne pourra plus accéder au lien.')">
            🗑 Supprimer
          </button>
        </form>

      </div>
    </div>
    <?php endforeach; ?>

  </div>
  <?php endforeach; ?>
  <?php endif; ?>

</main>

<footer>
  <strong>Cryptex</strong> v<?= APP_VERSION ?> — Auvergne Habitat
</footer>

<script src="assets/app.js"></script>
</body>
</html>

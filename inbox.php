<?php
// ============================================================
//  CRYPTEX — Boîte de réception (secrets reçus)
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/mail.php';

requireSSO();

$received = getReceivedSecrets(userEmail());
$n = now();

// Séparer actifs / consultés / expirés
$active  = array_filter($received, fn($r) => $r['is_active'] && !$r['read_at']);
$read    = array_filter($received, fn($r) => !empty($r['read_at']));
$expired = array_filter($received, fn($r) => !$r['is_active'] && !$r['read_at']);

function timeLeft(string $d): string {
    $diff = (new DateTime($d, new DateTimeZone('UTC')))->getTimestamp() - time();
    if ($diff <= 0) return 'Expiré';
    $days = floor($diff / 86400);
    $h    = floor($diff / 3600);
    $m    = floor(($diff % 3600) / 60);
    if ($days >= 1) return $days . 'j ' . ($h % 24) . 'h';
    return $h . 'h ' . $m . 'm';
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
  <title>Cryptex — Mes réceptions</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/style.css">
  <style>
    .inbox-section { margin-bottom: 32px; }
    .inbox-section h3 {
      font-size: 14px; font-weight: 700; text-transform: uppercase;
      letter-spacing: .8px; color: var(--text-muted);
      margin-bottom: 12px; padding-bottom: 8px;
      border-bottom: 2px solid var(--border);
      display: flex; align-items: center; gap: 8px;
    }
    .inbox-section h3 .count {
      background: var(--border); color: var(--text-muted);
      border-radius: 10px; padding: 1px 8px; font-size: 12px;
    }
    .inbox-section h3 .count.active { background: #d1f7ee; color: #065f46; }

    .message-card {
      background: #fff; border-radius: var(--radius-sm);
      border: 1px solid var(--border);
      box-shadow: 0 1px 6px rgba(30,58,95,.06);
      padding: 16px 20px;
      margin-bottom: 10px;
      display: flex; align-items: center; gap: 16px;
      transition: .15s;
    }
    .message-card:hover { box-shadow: 0 3px 12px rgba(30,58,95,.12); }
    .message-card.unread { border-left: 4px solid var(--primary-mid); }
    .message-card.already-read { opacity: .75; }
    .message-card.expired-card { opacity: .5; }

    .msg-icon { font-size: 28px; flex-shrink: 0; }

    .msg-body { flex: 1; min-width: 0; }
    .msg-title { font-weight: 700; font-size: 15px; color: var(--text);
                 white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .msg-meta  { font-size: 12px; color: var(--text-muted); margin-top: 4px;
                 display: flex; gap: 14px; flex-wrap: wrap; }

    .msg-status {
      display: inline-flex; align-items: center; gap: 5px;
      padding: 3px 10px; border-radius: 12px;
      font-size: 11px; font-weight: 700; flex-shrink: 0;
    }
    .status-new     { background: #dbeafe; color: #1e40af; }
    .status-read    { background: #d1f7ee; color: #065f46; }
    .status-expired { background: #f0f4f8; color: #999; }

    .msg-actions { flex-shrink: 0; }

    .btn-open {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 8px 16px; font-size: 13px; font-weight: 600;
      background: linear-gradient(135deg, var(--primary-mid), var(--primary-light));
      color: #fff; border-radius: 6px; text-decoration: none;
      transition: .15s;
    }
    .btn-open:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,90,156,.3); }

    .empty-section {
      text-align: center; padding: 24px; color: var(--text-muted);
      background: #f8fafc; border-radius: var(--radius-sm);
      font-size: 14px;
    }

    .tabs {
      display: flex; gap: 0; margin-bottom: 24px;
      border-bottom: 2px solid var(--border);
    }
    .tab-btn {
      padding: 10px 20px; font-size: 14px; font-weight: 600;
      background: none; border: none; cursor: pointer;
      color: var(--text-muted); border-bottom: 3px solid transparent;
      margin-bottom: -2px; font-family: inherit; transition: .15s;
    }
    .tab-btn.active { color: var(--primary); border-bottom-color: var(--primary-mid); }
    .tab-btn:hover { color: var(--primary); }

    .tab-panel { display: none; }
    .tab-panel.active { display: block; }
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
  <div><h1>CRYPTEX</h1><div class="tagline">Mes réceptions · Auvergne Habitat</div></div>
</header>

<nav class="nav-tabs">
  <a href="index.php">✉ Déposer</a>
  <a href="history.php">📋 Mes envois</a>
  <a href="inbox.php" class="active">📥 Mes réceptions</a>
  <?php if (isAdmin()): ?><a href="admin.php">👥 Utilisateurs</a><?php endif; ?>
</nav>

<main>

  <!-- En-tête avec compteur -->
  <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:24px;">
    <div>
      <h2 style="font-size:18px;color:var(--primary);margin-bottom:4px;">
        Mes réceptions
      </h2>
      <p style="font-size:13px;color:var(--text-muted);">
        Secrets qui vous ont été transmis via Cryptex.
      </p>
    </div>
    <?php if (count($active) > 0): ?>
    <div style="background:#dbeafe;color:#1e40af;padding:8px 16px;border-radius:20px;font-weight:700;font-size:14px;">
      📬 <?= count($active) ?> nouveau<?= count($active) > 1 ? 'x' : '' ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Onglets -->
  <div class="tabs">
    <button class="tab-btn active" onclick="showTab('tab-new', this)">
      📬 À consulter (<?= count($active) ?>)
    </button>
    <button class="tab-btn" onclick="showTab('tab-read', this)">
      ✅ Consultés (<?= count($read) ?>)
    </button>
    <button class="tab-btn" onclick="showTab('tab-expired', this)">
      ⌛ Expirés (<?= count($expired) ?>)
    </button>
  </div>

  <!-- Onglet : À consulter -->
  <div class="tab-panel active" id="tab-new">
    <?php if (empty($active)): ?>
    <div class="empty-section">📭 Aucun secret en attente de consultation.</div>
    <?php else: ?>
    <?php foreach ($active as $r): ?>
    <div class="message-card unread">
      <div class="msg-icon">🔏</div>
      <div class="msg-body">
        <div class="msg-title"><?= htmlspecialchars($r['title'] ?: 'Information confidentielle') ?></div>
        <div class="msg-meta">
          <span>👤 De : <strong><?= htmlspecialchars($r['sender_name']) ?></strong></span>
          <span>📅 Reçu le <?= fmtDate($r['created_at']) ?></span>
          <span style="color:<?= ((new DateTime($r['expires_at'], new DateTimeZone('UTC')))->getTimestamp() - time()) < 3600*4 ? '#ef476f' : 'inherit' ?>">
            ⏱ Expire dans <?= timeLeft($r['expires_at']) ?>
          </span>
          <?php if ($r['destroy_on_read']): ?>
          <span style="color:#f59e0b;">⚡ Lecture unique</span>
          <?php endif; ?>
        </div>
      </div>
      <span class="msg-status status-new">🆕 Non lu</span>
      <div class="msg-actions">
        <a href="view.php?t=<?= urlencode($r['token']) ?>" class="btn-open">
          🔓 Consulter
        </a>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Onglet : Consultés -->
  <div class="tab-panel" id="tab-read">
    <?php if (empty($read)): ?>
    <div class="empty-section">Vous n'avez pas encore consulté de secrets.</div>
    <?php else: ?>
    <?php foreach ($read as $r): ?>
    <div class="message-card already-read">
      <div class="msg-icon">✅</div>
      <div class="msg-body">
        <div class="msg-title"><?= htmlspecialchars($r['title'] ?: 'Information confidentielle') ?></div>
        <div class="msg-meta">
          <span>👤 De : <?= htmlspecialchars($r['sender_name']) ?></span>
          <span>📅 Reçu le <?= fmtDate($r['created_at']) ?></span>
          <span>👁 Consulté le <?= fmtDate($r['read_at']) ?></span>
        </div>
      </div>
      <span class="msg-status status-read">✅ Consulté</span>
      <div class="msg-actions">
        <?php if ($r['is_active'] && !$r['destroy_on_read']): ?>
        <a href="view.php?t=<?= urlencode($r['token']) ?>" class="btn-open" style="background:linear-gradient(135deg,#065f46,#06d6a0);">
          🔓 Reconsulter
        </a>
        <?php else: ?>
        <span style="font-size:12px;color:var(--text-muted);padding:8px;">
          <?= $r['destroy_on_read'] ? '⚡ Détruit' : '⌛ Expiré' ?>
        </span>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Onglet : Expirés -->
  <div class="tab-panel" id="tab-expired">
    <?php if (empty($expired)): ?>
    <div class="empty-section">Aucun secret expiré non consulté.</div>
    <?php else: ?>
    <?php foreach ($expired as $r): ?>
    <div class="message-card expired-card">
      <div class="msg-icon">⌛</div>
      <div class="msg-body">
        <div class="msg-title"><?= htmlspecialchars($r['title'] ?: 'Information confidentielle') ?></div>
        <div class="msg-meta">
          <span>👤 De : <?= htmlspecialchars($r['sender_name']) ?></span>
          <span>📅 Reçu le <?= fmtDate($r['created_at']) ?></span>
          <span>🚫 Expiré le <?= fmtDate($r['expires_at']) ?></span>
        </div>
      </div>
      <span class="msg-status status-expired">⌛ Expiré</span>
      <div class="msg-actions">
        <span style="font-size:12px;color:var(--text-muted);padding:8px;">Non disponible</span>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>

</main>

<footer>
  <strong>Cryptex</strong> v<?= APP_VERSION ?> — Auvergne Habitat
</footer>

<script src="assets/app.js"></script>
<script>
function showTab(id, btn) {
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById(id).classList.add('active');
  btn.classList.add('active');
}
</script>

</body>
</html>

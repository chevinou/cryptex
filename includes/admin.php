<?php
// ============================================================
//  CRYPTEX — Panel d'administration (rôles utilisateurs)
// ============================================================

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

requireAdmin();

$flash   = null;
$filter  = $_GET['filter'] ?? '';
$search  = trim($_GET['q'] ?? '');

// ── Actions POST ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCsrf($_POST['csrf_token'] ?? '')) {
        $flash = ['type' => 'danger', 'msg' => 'Token CSRF invalide.'];
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'set_role') {
            $uid  = (int)($_POST['user_id'] ?? 0);
            $role = $_POST['role'] ?? '';

            // Empêcher l'admin de se rétrograder lui-même
            $dbUser = currentDbUser();
            if ($uid === (int)($dbUser['id'] ?? 0) && $role !== 'admin') {
                $flash = ['type' => 'warning', 'msg' => 'Vous ne pouvez pas modifier votre propre rôle.'];
            } elseif (setUserRole($uid, $role)) {
                auditLog('', 'role_changed_to_'.$role, userEmail(), getClientIp());
                $flash = ['type' => 'success', 'msg' => 'Rôle mis à jour avec succès.'];
            } else {
                $flash = ['type' => 'danger', 'msg' => 'Rôle invalide.'];
            }
        }
    }
}

// ── Chargement utilisateurs ───────────────────────────────────
$users = getAllUsers($filter);

if ($search) {
    $s = strtolower($search);
    $users = array_filter($users, fn($u) =>
        str_contains(strtolower($u['nom']     ?? ''), $s) ||
        str_contains(strtolower($u['prenom']  ?? ''), $s) ||
        str_contains(strtolower($u['email']   ?? ''), $s) ||
        str_contains(strtolower($u['service'] ?? ''), $s)
    );
}

$stats = getStats();
$csrf  = csrfToken();

$roleBadge = [
    'admin'   => ['label' => 'Admin',   'color' => '#1e3a5f', 'bg' => '#d1e3fa'],
    'user'    => ['label' => 'Utilisateur', 'color' => '#065f46', 'bg' => '#d1f7ee'],
    'blocked' => ['label' => 'Bloqué',  'color' => '#9b1239', 'bg' => '#fde8ed'],
];
$roleLabels = ['admin' => 'Administrateur', 'user' => 'Utilisateur', 'blocked' => 'Bloqué'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Cryptex — Administration</title>
  <link rel="stylesheet" href="assets/style.css">
  <style>
    .badge-role {
      display:inline-block; padding:3px 10px; border-radius:20px;
      font-size:11px; font-weight:700; letter-spacing:.5px; text-transform:uppercase;
    }
    .user-row td { vertical-align:middle; padding:12px 14px; border-bottom:1px solid var(--border); font-size:14px; }
    .user-row:last-child td { border-bottom:none; }
    .user-row:hover td { background:#f8fafc; }
    .role-form { display:flex; align-items:center; gap:8px; }
    .role-select {
      padding:5px 10px; border:2px solid var(--border); border-radius:6px;
      font-size:13px; background:#fff; color:var(--text); cursor:pointer;
    }
    .role-select:focus { border-color:var(--primary-light); outline:none; }
    .source-tag {
      font-size:10px; padding:2px 7px; border-radius:10px;
      background:#f0f4f8; color:var(--text-muted); font-weight:600;
    }
    .avatar-sm {
      width:34px; height:34px; border-radius:50%;
      background:linear-gradient(135deg,var(--primary-mid),var(--accent));
      display:inline-flex; align-items:center; justify-content:center;
      color:#fff; font-weight:700; font-size:12px; flex-shrink:0;
    }
    .user-info { display:flex; align-items:center; gap:10px; }
    .filters { display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-bottom:16px; }
    .filter-btn {
      padding:6px 14px; border-radius:20px; font-size:13px; font-weight:600;
      border:2px solid var(--border); background:#fff; cursor:pointer;
      text-decoration:none; color:var(--text-muted); transition:.15s;
    }
    .filter-btn:hover, .filter-btn.active { border-color:var(--primary-mid); color:var(--primary-mid); }
    .filter-btn.active { background:#eef5ff; }
    .search-box { flex:1; min-width:200px; }
    table { width:100%; border-collapse:collapse; }
    thead th {
      padding:10px 14px; text-align:left; font-size:12px; font-weight:700;
      color:var(--text-muted); text-transform:uppercase; letter-spacing:.5px;
      background:#f8fafc; border-bottom:2px solid var(--border);
    }
    .stat-num { font-weight:700; color:var(--primary); }
  </style>
</head>
<body>

<!-- Topbar SSO -->
<div class="topbar">
  <div>
    <strong><?= htmlspecialchars(fullName()) ?></strong>
    &nbsp;·&nbsp; <span style="color:#f9d879">👑 Administrateur</span>
  </div>
  <a href="logout.php">⎋ Déconnexion</a>
</div>

<!-- Header -->
<header class="site-header">
  <div class="logo-mark">🔐</div>
  <div>
    <h1>CRYPTEX</h1>
    <div class="tagline">Gestion des utilisateurs · Auvergne Habitat</div>
  </div>
</header>

<!-- Nav -->
<nav class="nav-tabs">
  <a href="index.php">✉ Déposer un secret</a>
  <a href="history.php">📋 Mes envois</a>
  <a href="admin.php" class="active">👥 Utilisateurs</a>
</nav>

<main>

  <!-- Stats rapides -->
  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-icon">👥</div>
      <div class="stat-value"><?= $stats['users'] ?></div>
      <div class="stat-label">Utilisateurs</div>
    </div>
    <div class="stat-card">
      <div class="stat-icon">📨</div>
      <div class="stat-value"><?= $stats['total'] ?></div>
      <div class="stat-label">Secrets créés</div>
    </div>
    <div class="stat-card">
      <div class="stat-icon">🔓</div>
      <div class="stat-value"><?= $stats['active'] ?></div>
      <div class="stat-label">En attente</div>
    </div>
    <div class="stat-card">
      <div class="stat-icon">🚫</div>
      <div class="stat-value"><?= $stats['blocked'] ?></div>
      <div class="stat-label">Bloqués</div>
    </div>
  </div>

  <?php if ($flash): ?>
  <div class="alert alert-<?= $flash['type'] ?>"><?= htmlspecialchars($flash['msg']) ?></div>
  <?php endif; ?>

  <div class="card">
    <div class="card-header">
      <span class="icon">👥</span>
      <h2>Gestion des utilisateurs (<?= count($users) ?>)</h2>
    </div>
    <div class="card-body">

      <!-- Filtres et recherche -->
      <div class="filters">
        <a href="admin.php" class="filter-btn <?= !$filter ? 'active' : '' ?>">Tous</a>
        <a href="admin.php?filter=admin"   class="filter-btn <?= $filter==='admin'   ? 'active' : '' ?>">👑 Admins</a>
        <a href="admin.php?filter=user"    class="filter-btn <?= $filter==='user'    ? 'active' : '' ?>">✅ Utilisateurs</a>
        <a href="admin.php?filter=blocked" class="filter-btn <?= $filter==='blocked' ? 'active' : '' ?>">🚫 Bloqués</a>
        <form method="GET" style="display:flex;gap:8px;flex:1;" action="admin.php">
          <?php if ($filter): ?><input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>"><?php endif; ?>
          <input type="text" name="q" value="<?= htmlspecialchars($search) ?>"
                 placeholder="🔍 Rechercher par nom, email, service…"
                 class="search-box" style="max-width:320px;">
          <button type="submit" class="btn btn-outline" style="padding:7px 16px;font-size:13px;">Filtrer</button>
        </form>
      </div>

      <?php if (empty($users)): ?>
      <p style="color:var(--text-muted);text-align:center;padding:30px;">Aucun utilisateur trouvé.</p>
      <?php else: ?>
      <div style="overflow-x:auto;">
      <table>
        <thead>
          <tr>
            <th>Utilisateur</th>
            <th>Service / Poste</th>
            <th>Dernière connexion</th>
            <th style="text-align:center;">Envoyés</th>
            <th style="text-align:center;">Reçus</th>
            <th>Source</th>
            <th>Rôle</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u):
            $initials = strtoupper(substr($u['prenom']??'?',0,1) . substr($u['nom']??'?',0,1));
            $rb = $roleBadge[$u['role']] ?? $roleBadge['user'];
            $lastLogin = $u['last_login'] ? (new DateTime($u['last_login']))->format('d/m/Y H:i') : '—';
            $isSelf = $u['email'] === userEmail();
          ?>
          <tr class="user-row">
            <td>
              <div class="user-info">
                <div class="avatar-sm"><?= $initials ?></div>
                <div>
                  <div style="font-weight:600;"><?= htmlspecialchars(($u['prenom']??'').' '.($u['nom']??'')) ?></div>
                  <div style="font-size:12px;color:var(--text-muted);"><?= htmlspecialchars($u['email']) ?></div>
                </div>
              </div>
            </td>
            <td>
              <div style="font-size:13px;"><?= htmlspecialchars($u['service']??'—') ?></div>
              <div style="font-size:12px;color:var(--text-muted);"><?= htmlspecialchars($u['poste']??'') ?></div>
            </td>
            <td style="font-size:13px;color:var(--text-muted);"><?= $lastLogin ?></td>
            <td style="text-align:center;" class="stat-num"><?= (int)$u['secrets_sent'] ?></td>
            <td style="text-align:center;" class="stat-num"><?= (int)$u['secrets_received'] ?></td>
            <td>
              <span class="source-tag"><?= $u['source'] === 'ad_sync' ? '🔄 AD sync' : '🔑 SSO' ?></span>
            </td>
            <td>
              <?php if ($isSelf): ?>
                <span class="badge-role" style="background:<?= $rb['bg'] ?>;color:<?= $rb['color'] ?>">
                  <?= $rb['label'] ?> (vous)
                </span>
              <?php else: ?>
              <form method="POST" class="role-form">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action"  value="set_role">
                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                <select name="role" class="role-select" onchange="this.form.submit()">
                  <?php foreach ($roleLabels as $val => $lbl): ?>
                  <option value="<?= $val ?>" <?= $u['role']===$val ? 'selected' : '' ?>>
                    <?= $lbl ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </form>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
      <?php endif; ?>

    </div>
  </div>

  <!-- Info sync AD -->
  <div class="alert alert-info">
    🔄 <strong>Synchronisation Active Directory :</strong>
    Les utilisateurs sont ajoutés automatiquement à la connexion SSO.
    Pour importer l'annuaire complet, configurez le cron :
    <code>0 3 * * * php /var/www/cryptex/cron_ad_sync.php</code>
  </div>

</main>

<footer>
  <strong>Cryptex</strong> v<?= APP_VERSION ?> — Auvergne Habitat · SI
</footer>
</body>
</html>

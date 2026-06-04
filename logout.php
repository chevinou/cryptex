<?php
require_once __DIR__ . '/config.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start(['cookie_httponly' => true, 'cookie_samesite' => 'Strict']);
}

$userName = trim(($_SESSION['sso_data']['prenom'] ?? '') . ' ' . ($_SESSION['sso_data']['nom'] ?? ''));

session_unset();
session_destroy();

if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 3600, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Cryptex — Déconnexion</title>
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/style.css">
  <style>
    body { justify-content:center; align-items:center; display:flex; flex-direction:column; min-height:100vh; }
    .logout-card {
      background:var(--card); border-radius:var(--radius); box-shadow:var(--shadow);
      padding:52px 48px; text-align:center; max-width:440px; width:100%;
      animation:fadeIn .4s ease;
    }
    @keyframes fadeIn { from{opacity:0;transform:translateY(12px)} to{opacity:1;transform:none} }
    .lock-icon {
      width:72px;height:72px;background:linear-gradient(135deg,#f0f4f8,#e0e8f0);
      border-radius:50%;display:flex;align-items:center;justify-content:center;
      font-size:36px;margin:0 auto 24px;border:3px solid var(--border);
    }
    .logout-card h1 { font-size:22px;color:var(--primary);margin-bottom:10px; }
    .logout-card .subtitle { color:var(--text-muted);font-size:14px;margin-bottom:6px; }
    .logout-card .username { font-weight:700;color:var(--text);font-size:15px;margin-bottom:28px; }
    .divider { border:none;border-top:1px solid var(--border);margin:24px 0; }
    .btn-reconnect {
      width:100%;justify-content:center;padding:14px;font-size:15px;
      background:linear-gradient(135deg,var(--primary),var(--primary-mid));
      color:#fff;border:none;border-radius:var(--radius-sm);cursor:pointer;
      font-weight:700;text-decoration:none;display:inline-flex;
      align-items:center;gap:8px;transition:.2s;font-family:inherit;
    }
    .btn-reconnect:hover { transform:translateY(-1px);box-shadow:0 6px 18px rgba(0,90,156,.3); }
    .security-note { margin-top:16px;font-size:12px;color:var(--text-muted);line-height:1.5; }
    .app-footer { margin-top:32px;font-size:11px;color:var(--text-muted); }
    .app-footer strong { color:var(--primary-mid); }
  </style>
</head>
<body>
  <div class="logout-card">
    <div class="lock-icon">🔒</div>
    <h1>Vous êtes déconnecté</h1>
    <?php if ($userName): ?>
    <p class="subtitle">Au revoir,</p>
    <p class="username"><?= htmlspecialchars($userName) ?></p>
    <?php else: ?>
    <p class="subtitle" style="margin-bottom:28px;">Votre session a été fermée.</p>
    <?php endif; ?>
    <div class="alert alert-info" style="text-align:left;font-size:13px;">
      🛡 Session locale supprimée. Fermez le navigateur pour une déconnexion complète du SSO.
    </div>
    <hr class="divider">
    <a href="index.php" class="btn-reconnect">🔐 Se reconnecter</a>
    <p class="security-note">Vous serez redirigé vers le portail SSO Auvergne Habitat.</p>
  </div>
  <div class="app-footer"><strong>Cryptex</strong> — Transmission sécurisée · Auvergne Habitat</div>
</body>
</html>

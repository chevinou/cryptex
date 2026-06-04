<?php
// ============================================================
//  CRYPTEX — Page de connexion
//  Affiche les modes d'authentification activés dans config.php
// ============================================================
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/db.php';

startSession();

// Déjà connecté → accueil
if (!empty($_SESSION['authenticated'])) {
    header('Location: index.php');
    exit;
}

$error    = htmlspecialchars($_GET['error'] ?? '');
$redirect = htmlspecialchars($_GET['redirect'] ?? 'index.php');

// Déterminer les modes disponibles
$modes = [];
if (AUTH_SSO_ENABLED)   $modes[] = 'sso';
if (AUTH_LDAP_ENABLED)  $modes[] = 'ldap';
if (AUTH_LOCAL_ENABLED) $modes[] = 'local';

if (empty($modes)) {
    die('<p style="color:red;font-family:sans-serif;text-align:center;margin-top:4rem;">
        ⚠️ Aucun mode d\'authentification activé dans config.php.</p>');
}

$defaultMode = in_array(AUTH_DEFAULT_MODE, $modes) ? AUTH_DEFAULT_MODE : $modes[0];

$modeLabels = [
    'sso'   => '🔐 SSO / SAML',
    'ldap'  => '🏢 Active Directory',
    'local' => '👤 Compte local',
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Connexion — <?= htmlspecialchars(APP_NAME) ?></title>
    <link rel="stylesheet" href="assets/style.css">
    <style>
        .login-wrap   { display:flex; align-items:center; justify-content:center; min-height:100vh; padding:1rem; }
        .login-card   { max-width:420px; width:100%; }
        .auth-tabs    { display:flex; gap:.5rem; margin-bottom:1.5rem; }
        .auth-tab     { flex:1; padding:.6rem; border:2px solid #dee2e6; border-radius:8px;
                        background:#fff; cursor:pointer; font-size:.9rem; text-align:center;
                        color:#555; transition:all .15s; }
        .auth-tab.active { border-color:#4361ee; background:#eef1ff; color:#4361ee; font-weight:600; }
        .auth-panel   { display:none; }
        .auth-panel.active { display:block; }
        .logo         { text-align:center; margin-bottom:1.5rem; }
        .logo img     { height:56px; }
        .logo h1      { font-size:1.4rem; margin:.5rem 0 0; }
        .error-box    { background:#ffeaea; border:1px solid #f8c; border-radius:8px;
                        padding:.75rem 1rem; color:#c00; margin-bottom:1rem; font-size:.9rem; }
    </style>
</head>
<body>
<div class="login-wrap">
  <div class="card login-card">
    <div class="logo">
      <img src="img/logo.png" alt="<?= htmlspecialchars(APP_NAME) ?>">
      <h1><?= htmlspecialchars(APP_NAME) ?></h1>
      <p style="color:#777;font-size:.9rem;margin:.25rem 0 0;">Transmission sécurisée d'informations</p>
    </div>

    <?php if ($error): ?>
      <div class="error-box">⚠️ <?= $error ?></div>
    <?php endif; ?>

    <?php if (count($modes) > 1): ?>
    <div class="auth-tabs">
      <?php foreach ($modes as $m): ?>
        <button class="auth-tab <?= $m === $defaultMode ? 'active' : '' ?>"
                onclick="switchTab('<?= $m ?>')">
          <?= $modeLabels[$m] ?? ucfirst($m) ?>
        </button>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php /* ─── SSO ─── */ if (AUTH_SSO_ENABLED): ?>
    <div id="panel-sso" class="auth-panel <?= $defaultMode === 'sso' ? 'active' : '' ?>">
      <p style="text-align:center;color:#555;margin-bottom:1.5rem;">
        Authentifiez-vous via votre portail <?= htmlspecialchars(APP_NAME) ?>.
      </p>
      <a href="SSO/authSSO.php?return=<?= urlencode($redirect) ?>"
         class="btn btn-primary" style="width:100%;display:block;text-align:center;">
        Connexion SSO →
      </a>
    </div>
    <?php endif; ?>

    <?php /* ─── LDAP ─── */ if (AUTH_LDAP_ENABLED): ?>
    <div id="panel-ldap" class="auth-panel <?= $defaultMode === 'ldap' ? 'active' : '' ?>">
      <form method="post" action="login.php" autocomplete="on">
        <input type="hidden" name="auth_mode" value="ldap">
        <input type="hidden" name="redirect"  value="<?= $redirect ?>">
        <div class="form-group">
          <label for="ldap-login">Login Active Directory</label>
          <input id="ldap-login" type="text" name="login"
                 class="form-control" placeholder="jdupont"
                 autocomplete="username" required>
        </div>
        <div class="form-group">
          <label for="ldap-pass">Mot de passe</label>
          <input id="ldap-pass" type="password" name="password"
                 class="form-control" placeholder="••••••••"
                 autocomplete="current-password" required>
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%;">
          Connexion AD →
        </button>
      </form>
    </div>
    <?php endif; ?>

    <?php /* ─── Local ─── */ if (AUTH_LOCAL_ENABLED): ?>
    <div id="panel-local" class="auth-panel <?= $defaultMode === 'local' ? 'active' : '' ?>">
      <form method="post" action="login.php" autocomplete="on">
        <input type="hidden" name="auth_mode" value="local">
        <input type="hidden" name="redirect"  value="<?= $redirect ?>">
        <div class="form-group">
          <label for="local-login">Identifiant</label>
          <input id="local-login" type="text" name="login"
                 class="form-control" placeholder="jdupont"
                 autocomplete="username" required>
        </div>
        <div class="form-group">
          <label for="local-pass">Mot de passe</label>
          <input id="local-pass" type="password" name="password"
                 class="form-control" placeholder="••••••••"
                 autocomplete="current-password" required>
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%;">
          Connexion →
        </button>
      </form>
    </div>
    <?php endif; ?>

  </div>
</div>
<script>
function switchTab(mode) {
  document.querySelectorAll('.auth-tab').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.auth-panel').forEach(p => p.classList.remove('active'));
  document.querySelector('#panel-' + mode).classList.add('active');
  event.target.classList.add('active');
}
</script>
</body>
</html>

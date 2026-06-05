<?php
// ============================================================
//  CRYPTEX — Page de connexion
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

// ── Traitement du POST (login LDAP ou local) ──────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auth_mode'])) {
    handlePostLogin();
    exit;
}

$error    = htmlspecialchars($_GET['error'] ?? '');
$redirect = htmlspecialchars($_GET['redirect'] ?? 'index.php');

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
  <link rel="icon" type="image/svg+xml" href="assets/favicon.svg">
  <link rel="stylesheet" href="assets/style.css">
  <style>
    body { justify-content:center; align-items:center; display:flex; flex-direction:column; min-height:100vh; }

    .login-card {
      background:var(--card); border-radius:var(--radius); box-shadow:var(--shadow);
      padding:52px 48px; max-width:440px; width:100%;
      animation:fadeIn .4s ease;
    }
    @keyframes fadeIn { from{opacity:0;transform:translateY(12px)} to{opacity:1;transform:none} }

    .login-logo {
      text-align:center; margin-bottom:28px;
    }
    .login-logo img { height:56px; margin-bottom:12px; }
    .login-logo h1  { font-size:22px; color:var(--primary); margin:0 0 4px; }
    .login-logo p   { color:var(--text-muted); font-size:13px; margin:0; }

    /* Onglets de mode */
    .auth-tabs { display:flex; gap:8px; margin-bottom:24px; }
    .auth-tab  {
      flex:1; padding:10px 8px; border:2px solid var(--border);
      border-radius:var(--radius-sm); background:var(--card);
      cursor:pointer; font-size:13px; font-weight:600;
      color:var(--text-muted); text-align:center;
      transition:.15s; font-family:inherit;
    }
    .auth-tab:hover  { border-color:var(--primary-mid); color:var(--primary); }
    .auth-tab.active { border-color:var(--primary); background:var(--primary-light,#eef3fb); color:var(--primary); }

    .auth-panel { display:none; }
    .auth-panel.active { display:block; }

    /* Champs */
    .field { margin-bottom:18px; }
    .field label {
      display:block; font-size:13px; font-weight:600;
      color:var(--text); margin-bottom:6px;
    }
    .field-wrap { position:relative; }
    .field-wrap input[type="text"],
    .field-wrap input[type="password"] {
      width:100%; box-sizing:border-box;
      padding:11px 44px 11px 14px;
      border:1.5px solid var(--border); border-radius:var(--radius-sm);
      background:var(--bg,#f8fafc); color:var(--text);
      font-size:14px; font-family:inherit;
      transition:border-color .15s, box-shadow .15s;
      outline:none;
    }
    .field-wrap input:focus {
      border-color:var(--primary);
      box-shadow:0 0 0 3px rgba(0,90,156,.12);
    }
    .field-icon {
      position:absolute; right:13px; top:50%; transform:translateY(-50%);
      color:var(--text-muted); font-size:16px; pointer-events:none;
      user-select:none;
    }
    /* Toggle visibilité mot de passe */
    .toggle-pw {
      position:absolute; right:13px; top:50%; transform:translateY(-50%);
      background:none; border:none; cursor:pointer; padding:0;
      color:var(--text-muted); font-size:16px; line-height:1;
    }
    .toggle-pw:hover { color:var(--primary); }

    /* Bouton connexion */
    .btn-login {
      width:100%; padding:14px; font-size:15px; font-weight:700;
      background:linear-gradient(135deg,var(--primary),var(--primary-mid));
      color:#fff; border:none; border-radius:var(--radius-sm);
      cursor:pointer; font-family:inherit; display:flex;
      align-items:center; justify-content:center; gap:8px;
      transition:.2s; margin-top:4px;
    }
    .btn-login:hover { transform:translateY(-1px); box-shadow:0 6px 18px rgba(0,90,156,.3); }

    /* SSO button */
    .btn-sso {
      width:100%; padding:14px; font-size:15px; font-weight:700;
      background:linear-gradient(135deg,var(--primary),var(--primary-mid));
      color:#fff; border:none; border-radius:var(--radius-sm);
      cursor:pointer; font-family:inherit; display:inline-flex;
      align-items:center; justify-content:center; gap:8px;
      text-decoration:none; transition:.2s;
    }
    .btn-sso:hover { transform:translateY(-1px); box-shadow:0 6px 18px rgba(0,90,156,.3); }

    .sso-desc { color:var(--text-muted); font-size:13px; text-align:center; margin-bottom:20px; line-height:1.5; }

    .error-box {
      background:#fff0f0; border:1.5px solid #fcc; border-radius:var(--radius-sm);
      padding:11px 14px; color:#c00; font-size:13px; margin-bottom:20px;
      display:flex; align-items:center; gap:8px;
    }

    .app-footer { margin-top:24px; font-size:11px; color:var(--text-muted); text-align:center; }
    .app-footer strong { color:var(--primary-mid); }
  </style>
</head>
<body>

<div class="login-card">
  <div class="login-logo">
    <img src="img/logo.png" alt="<?= htmlspecialchars(APP_NAME) ?>">
    <h1><?= htmlspecialchars(APP_NAME) ?></h1>
    <p>Transmission sécurisée d'informations</p>
  </div>

  <?php if ($error): ?>
    <div class="error-box">⚠️ <?= $error ?></div>
  <?php endif; ?>

  <?php if (count($modes) > 1): ?>
  <div class="auth-tabs">
    <?php foreach ($modes as $m): ?>
      <button class="auth-tab <?= $m === $defaultMode ? 'active' : '' ?>"
              onclick="switchTab('<?= $m ?>', this)">
        <?= $modeLabels[$m] ?? ucfirst($m) ?>
      </button>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php /* ─── SSO ─── */ if (AUTH_SSO_ENABLED): ?>
  <div id="panel-sso" class="auth-panel <?= $defaultMode === 'sso' ? 'active' : '' ?>">
    <p class="sso-desc">
      Authentifiez-vous via le portail SSO de votre organisation.<br>
      Vous serez redirigé automatiquement.
    </p>
    <a href="SSO/authSSO.php?return=<?= urlencode($redirect) ?>" class="btn-sso">
      🔐 Connexion SSO →
    </a>
  </div>
  <?php endif; ?>

  <?php /* ─── LDAP ─── */ if (AUTH_LDAP_ENABLED): ?>
  <div id="panel-ldap" class="auth-panel <?= $defaultMode === 'ldap' ? 'active' : '' ?>">
    <form method="post" action="login.php" autocomplete="on">
      <input type="hidden" name="auth_mode" value="ldap">
      <input type="hidden" name="redirect"  value="<?= $redirect ?>">
      <div class="field">
        <label for="ldap-login">Login Active Directory</label>
        <div class="field-wrap">
          <input id="ldap-login" type="text" name="login"
                 placeholder="jdupont" autocomplete="username" required>
          <span class="field-icon">🏢</span>
        </div>
      </div>
      <div class="field">
        <label for="ldap-pass">Mot de passe</label>
        <div class="field-wrap">
          <input id="ldap-pass" type="password" name="password"
                 placeholder="••••••••" autocomplete="current-password" required>
          <button type="button" class="toggle-pw" onclick="togglePw('ldap-pass', this)" title="Afficher/masquer">👁</button>
        </div>
      </div>
      <button type="submit" class="btn-login">🏢 Connexion Active Directory →</button>
    </form>
  </div>
  <?php endif; ?>

  <?php /* ─── Local ─── */ if (AUTH_LOCAL_ENABLED): ?>
  <div id="panel-local" class="auth-panel <?= $defaultMode === 'local' ? 'active' : '' ?>">
    <form method="post" action="login.php" autocomplete="on">
      <input type="hidden" name="auth_mode" value="local">
      <input type="hidden" name="redirect"  value="<?= $redirect ?>">
      <div class="field">
        <label for="local-login">Identifiant</label>
        <div class="field-wrap">
          <input id="local-login" type="text" name="login"
                 placeholder="jdupont" autocomplete="username" required>
          <span class="field-icon">👤</span>
        </div>
      </div>
      <div class="field">
        <label for="local-pass">Mot de passe</label>
        <div class="field-wrap">
          <input id="local-pass" type="password" name="password"
                 placeholder="••••••••" autocomplete="current-password" required>
          <button type="button" class="toggle-pw" onclick="togglePw('local-pass', this)" title="Afficher/masquer">👁</button>
        </div>
      </div>
      <button type="submit" class="btn-login">🔓 Connexion →</button>
    </form>
  </div>
  <?php endif; ?>

</div>

<div class="app-footer"><strong>Cryptex</strong> — Transmission sécurisée</div>

<script>
function switchTab(mode, btn) {
  document.querySelectorAll('.auth-tab').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.auth-panel').forEach(p => p.classList.remove('active'));
  document.getElementById('panel-' + mode).classList.add('active');
  btn.classList.add('active');
}
function togglePw(id, btn) {
  const input = document.getElementById(id);
  const show  = input.type === 'password';
  input.type  = show ? 'text' : 'password';
  btn.textContent = show ? '🙈' : '👁';
}
</script>
</body>
</html>

<?php
// ============================================================
//  CRYPTEX — Authentification multi-modes
//  Modes supportés (configurables dans config.php) :
//    • SSO   : portail SAML centralisé
//    • LDAP  : Active Directory / OpenLDAP (login + mot de passe)
//    • Local : comptes stockés en base de données Cryptex
//
//  Au moins un mode doit être activé. Plusieurs peuvent
//  coexister : l'UI affiche des onglets de connexion.
// ============================================================

// ── Helpers de session ────────────────────────────────────────

function startSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start([
            'cookie_httponly' => true,
            'cookie_samesite' => 'Lax',
        ]);
    }
}

// ── Point d'entrée principal ──────────────────────────────────

/**
 * Appel depuis chaque page protégée.
 * Redirige vers la page de connexion si non authentifié,
 * bloque si le rôle est 'blocked'.
 */
function requireAuth(): void {
    startSession();

    // Expiration de session
    if (isset($_SESSION['auth_time'])
        && (time() - $_SESSION['auth_time']) > SESSION_LIFETIME) {
        session_destroy();
        redirectToLogin();
    }

    // Retour SSO (token dans l'URL)
    if (AUTH_SSO_ENABLED && isset($_GET['sso_token']) && !isset($_SESSION['attributes'])) {
        handleSSOCallback();
    }

    // Non connecté → page de connexion
    if (!isset($_SESSION['authenticated'])) {
        // Tentative de login LDAP ou local via POST
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['auth_mode'])) {
            handlePostLogin();
        } else {
            redirectToLogin();
        }
    }

    // Compte bloqué
    if (($_SESSION['role'] ?? '') === 'blocked') {
        http_response_code(403);
        die(renderBlocked());
    }
}

function requireAdmin(): void {
    requireAuth();
    if (!isAdmin()) {
        http_response_code(403);
        die(renderAccessDenied());
    }
}

// ── Mode SSO ─────────────────────────────────────────────────

function handleSSOCallback(): void {
    $token = $_GET['sso_token'];
    if (!ctype_xdigit($token) || strlen($token) !== 64) {
        die('Token SSO invalide.');
    }

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query(['sso_token' => $token]),
            'timeout' => 5,
        ],
        'ssl'  => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);

    $response = @file_get_contents(SSO_URL . '/token_exchange.php', false, $ctx);
    if ($response === false) die('Impossible de joindre le serveur SSO.');

    $data = json_decode($response, true);
    if (empty($data['ok'])) {
        die('Échec SSO : ' . htmlspecialchars($data['error'] ?? 'Erreur inconnue'));
    }

    $attributes = $data['attributes'];
    $get = fn(string $k) => $attributes[$k][0] ?? '';

    $ssoData = [
        'prenom'         => $get('http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname'),
        'nom'            => $get('http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname'),
        'email'          => $get('http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress'),
        'service'        => $get('department'),
        'poste'          => $get('jobtitle'),
        'telephone'      => $get('telephone'),
        'site'           => $get('officelocation'),
        'samaccountname' => $get('samaccountname'),
    ];

    $_SESSION['attributes']   = $attributes;
    $_SESSION['sso_data']     = $ssoData;
    $_SESSION['auth_time']    = time();
    $_SESSION['auth_method']  = 'sso';
    $_SESSION['authenticated'] = true;
    $_SESSION['role']         = 'user';

    try {
        $dbUser = registerOrUpdateUser($ssoData);
        $_SESSION['db_user'] = $dbUser;
        $_SESSION['role']    = $dbUser['role'] ?? 'user';
    } catch (Throwable $e) {
        error_log('[Cryptex] registerOrUpdateUser: ' . $e->getMessage());
    }

    $redirectAfter = $_SESSION['sso_redirect_after'] ?? null;
    unset($_SESSION['sso_redirect_after']);
    session_write_close();

    $proto = isHttps() ? 'https' : 'http';
    if ($redirectAfter) {
        header('Location: ' . $proto . '://' . $_SERVER['HTTP_HOST'] . $redirectAfter);
    } else {
        $url    = strtok($_SERVER['REQUEST_URI'], '?');
        $params = $_GET;
        unset($params['sso_token']);
        $qs = $params ? '?' . http_build_query($params) : '';
        header('Location: ' . $proto . '://' . $_SERVER['HTTP_HOST'] . $url . $qs);
    }
    exit;
}

function redirectToSSO(): void {
    startSession();
    $_SESSION['sso_redirect_after'] = $_SERVER['REQUEST_URI'];
    session_write_close();

    $proto    = isHttps() ? 'https' : 'http';
    $cleanUri = strtok($_SERVER['REQUEST_URI'], '?');
    $returnUrl = urlencode($proto . '://' . $_SERVER['HTTP_HOST'] . $cleanUri);
    header('Location: ' . SSO_URL . '?return=' . $returnUrl);
    exit;
}

// ── Mode LDAP ─────────────────────────────────────────────────

/**
 * Authentifie un utilisateur via bind LDAP (login AD + mot de passe).
 * Retourne les données utilisateur ou false.
 */
function authenticateLDAP(string $login, string $password): array|false {
    if (!AUTH_LDAP_ENABLED || empty($login) || empty($password)) return false;

    $ldap = @ldap_connect(LDAP_HOST, LDAP_PORT);
    if (!$ldap) return false;

    ldap_set_option($ldap, LDAP_OPT_PROTOCOL_VERSION, 3);
    ldap_set_option($ldap, LDAP_OPT_REFERRALS, 0);
    ldap_set_option($ldap, LDAP_OPT_NETWORK_TIMEOUT, 5);

    // Construction du UPN : login@domaine
    $upn = (str_contains($login, '@')) ? $login : ($login . '@' . LDAP_DOMAIN);

    // Tentative de bind avec les credentials de l'utilisateur
    $bound = @ldap_bind($ldap, $upn, $password);
    if (!$bound) {
        ldap_close($ldap);
        return false;
    }

    // Recherche des attributs de l'utilisateur
    // Bind avec compte de service si disponible, sinon bind utilisateur
    if (!empty(LDAP_BIND_USER)) {
        @ldap_bind($ldap, LDAP_BIND_USER, LDAP_BIND_PASS);
    }

    $safe   = ldapEscapeForAuth($login);
    $filter = "(&(objectClass=person)(objectCategory=user)"
            . "(!(userAccountControl:1.2.840.113556.1.4.803:=2))"
            . "(|(samaccountname={$safe})(userPrincipalName={$safe}@*)))";

    $attrs  = ['givenName', 'sn', 'mail', 'department', 'jobtitle',
               'samaccountname', 'officelocation', 'telephoneNumber'];
    $result = @ldap_search($ldap, LDAP_BASE_DN, $filter, $attrs, 0, 1);

    if (!$result) {
        ldap_close($ldap);
        return false;
    }

    $entries = ldap_get_entries($ldap, $result);
    ldap_close($ldap);

    if ($entries['count'] === 0) return false;

    $e = $entries[0];
    return [
        'prenom'         => $e['givenname'][0]       ?? '',
        'nom'            => $e['sn'][0]               ?? '',
        'email'          => strtolower($e['mail'][0]  ?? ''),
        'service'        => $e['department'][0]        ?? '',
        'poste'          => $e['jobtitle'][0]          ?? '',
        'telephone'      => $e['telephonenumber'][0]   ?? '',
        'site'           => $e['officelocation'][0]    ?? '',
        'samaccountname' => strtolower($e['samaccountname'][0] ?? $login),
    ];
}

// ── Mode Local ────────────────────────────────────────────────

/**
 * Authentifie un utilisateur via les comptes locaux Cryptex.
 * Le mot de passe est hashé avec password_hash(PASSWORD_BCRYPT).
 */
function authenticateLocal(string $login, string $password): array|false {
    if (!AUTH_LOCAL_ENABLED || empty($login) || empty($password)) return false;

    try {
        $db   = getDB();
        $stmt = $db->prepare(
            "SELECT * FROM local_users WHERE login = :l AND active = 1 LIMIT 1"
        );
        $stmt->execute([':l' => strtolower(trim($login))]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password_hash'])) {
            return false;
        }

        return [
            'prenom'         => $user['prenom']  ?? '',
            'nom'            => $user['nom']      ?? '',
            'email'          => $user['email']    ?? '',
            'service'        => $user['service']  ?? '',
            'poste'          => $user['poste']    ?? '',
            'telephone'      => $user['telephone'] ?? '',
            'site'           => $user['site']     ?? '',
            'samaccountname' => $user['login'],
        ];
    } catch (Throwable $e) {
        error_log('[Cryptex] authenticateLocal: ' . $e->getMessage());
        return false;
    }
}

// ── Gestion du POST de login ──────────────────────────────────

function handlePostLogin(): void {
    startSession();

    $mode     = $_POST['auth_mode'] ?? '';
    $login    = trim($_POST['login']    ?? '');
    $password = $_POST['password'] ?? '';
    $userData = false;

    if ($mode === 'ldap' && AUTH_LDAP_ENABLED) {
        $userData = authenticateLDAP($login, $password);
        $method   = 'ldap';
    } elseif ($mode === 'local' && AUTH_LOCAL_ENABLED) {
        $userData = authenticateLocal($login, $password);
        $method   = 'local';
    }

    if (!$userData) {
        // Échec → retour à la page de login avec erreur
        $redirectTo = $_SESSION['login_redirect'] ?? 'index.php';
        session_write_close();
        $qs = http_build_query([
            'error'    => 'Identifiants incorrects.',
            'redirect' => $redirectTo,
        ]);
        header('Location: login.php?' . $qs);
        exit;
    }

    // Succès
    session_regenerate_id(true);
    $_SESSION['sso_data']      = $userData;
    $_SESSION['auth_time']     = time();
    $_SESSION['auth_method']   = $method;
    $_SESSION['authenticated'] = true;
    $_SESSION['role']          = 'user';

    try {
        $dbUser = registerOrUpdateUser($userData, $method ?? 'local');
        $_SESSION['db_user'] = $dbUser;
        $_SESSION['role']    = $dbUser['role'] ?? 'user';
    } catch (Throwable $e) {
        error_log('[Cryptex] registerOrUpdateUser: ' . $e->getMessage());
    }

    $redirectTo = $_SESSION['login_redirect'] ?? 'index.php';
    unset($_SESSION['login_redirect']);
    session_write_close();

    header('Location: ' . $redirectTo);
    exit;
}

// ── Redirection vers la page de login ────────────────────────

function redirectToLogin(string $reason = ''): void {
    startSession();
    $_SESSION['login_redirect'] = $_SERVER['REQUEST_URI'];
    session_write_close();

    $qs = $reason ? '?error=' . urlencode($reason) : '';
    header('Location: login.php' . $qs);
    exit;
}

/**
 * Rétrocompatibilité : les pages qui appellent requireSSO()
 * utilisent désormais requireAuth().
 */
function requireSSO(): void {
    requireAuth();
}

function redirectToSSO_compat(): void {
    if (AUTH_SSO_ENABLED) {
        redirectToSSO();
    } else {
        redirectToLogin();
    }
}

// ── Accesseurs de session ─────────────────────────────────────

function currentUser(): array    { return $_SESSION['sso_data']  ?? []; }
function currentDbUser(): array  { return $_SESSION['db_user']   ?? []; }
function authMethod(): string    { return $_SESSION['auth_method'] ?? ''; }

function fullName(): string {
    $u = currentUser();
    return trim(($u['prenom'] ?? '') . ' ' . ($u['nom'] ?? ''));
}

function userEmail(): string {
    return strtolower(trim(($_SESSION['sso_data'] ?? [])['email'] ?? ''));
}

function userRole(): string { return $_SESSION['role'] ?? 'user'; }
function isAdmin(): bool    { return userRole() === 'admin'; }
function isBlocked(): bool  { return userRole() === 'blocked'; }

// ── CSRF ──────────────────────────────────────────────────────

function csrfToken(): string {
    startSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verifyCsrf(string $token): bool {
    return !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

// ── Utilitaires ───────────────────────────────────────────────

function getClientIp(): string {
    return $_SERVER['HTTP_X_FORWARDED_FOR']
        ?? $_SERVER['HTTP_X_REAL_IP']
        ?? $_SERVER['REMOTE_ADDR']
        ?? 'unknown';
}

function isHttps(): bool {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function ldapEscapeForAuth(string $str): string {
    return str_replace(
        ['\\', '*', '(', ')', "\x00"],
        ['\\5c', '\\2a', '\\28', '\\29', '\\00'],
        $str
    );
}

// ── Rendus HTML d'erreur ──────────────────────────────────────

function renderBlocked(): string {
    return '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8">
    <title>Accès suspendu — ' . APP_NAME . '</title>
    <link rel="stylesheet" href="assets/style.css"></head>
    <body style="display:flex;align-items:center;justify-content:center;min-height:100vh;">
    <div class="card" style="max-width:400px;text-align:center;padding:40px;">
    <div style="font-size:48px">🚫</div>
    <h2 style="color:#ef476f;margin:16px 0 12px;">Accès suspendu</h2>
    <p style="color:#555;">Votre compte a été suspendu.<br>Contactez votre administrateur.</p>
    </div></body></html>';
}

function renderAccessDenied(): string {
    return '<!DOCTYPE html><html lang="fr"><head><meta charset="UTF-8">
    <title>Accès refusé — ' . APP_NAME . '</title>
    <link rel="stylesheet" href="assets/style.css">
    </head><body style="display:flex;align-items:center;justify-content:center;min-height:100vh;">
    <div class="card" style="max-width:440px;text-align:center;padding:40px;">
      <div style="font-size:48px;margin-bottom:16px;">🚫</div>
      <h2 style="color:#ef476f;margin-bottom:12px;">Accès refusé</h2>
      <p style="color:#555;margin-bottom:24px;">
        Cette page est réservée aux administrateurs.<br>
        Votre rôle actuel : <strong>' . htmlspecialchars(userRole()) . '</strong>
      </p>
      <a href="index.php" class="btn btn-primary">← Retour à l\'accueil</a>
    </div></body></html>';
}

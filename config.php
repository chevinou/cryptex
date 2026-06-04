<?php
date_default_timezone_set('UTC');
// ============================================================
//  CRYPTEX — Configuration générale
//  Adaptez ce fichier à votre environnement avant déploiement.
//  En mode Docker : les variables d'environnement surchargent
//  les valeurs par défaut ci-dessous (préfixe CRYPTEX_).
// ============================================================

// ── Helpers : lecture depuis l'environnement (Docker / CI) ──
function env(string $key, mixed $default = ''): mixed {
    $val = getenv($key);
    return ($val !== false) ? $val : $default;
}
function envBool(string $key, bool $default = false): bool {
    $val = getenv($key);
    if ($val === false) return $default;
    return in_array(strtolower($val), ['1','true','yes','on'], true);
}
function envInt(string $key, int $default = 0): int {
    $val = getenv($key);
    return ($val !== false) ? (int)$val : $default;
}

// ============================================================
//  MODULE D'AUTHENTIFICATION
// ============================================================
//  Trois modes disponibles — activez ce dont vous avez besoin.
//  Vous pouvez combiner SSO + LOCAL ou LDAP + LOCAL.
//  Au moins un mode doit être activé.
//
//   'sso'   → Authentification via votre portail SSO/SAML
//             (module SSO/authSSO.php — voir SSO/config)
//   'ldap'  → Authentification Active Directory / LDAP
//             L'utilisateur saisit son login AD et son mot de passe.
//   'local' → Comptes locaux (login + mot de passe hashé en BDD)
//             Utile pour les organisations sans SSO ni AD.
// ============================================================

define('AUTH_SSO_ENABLED',   envBool('CRYPTEX_AUTH_SSO',   true));
define('AUTH_LDAP_ENABLED',  envBool('CRYPTEX_AUTH_LDAP',  false));
define('AUTH_LOCAL_ENABLED', envBool('CRYPTEX_AUTH_LOCAL', false));

// Ordre de priorité affiché sur la page de connexion
// (n'influe pas sur la sécurité, seulement sur l'UI)
define('AUTH_DEFAULT_MODE', env('CRYPTEX_AUTH_DEFAULT', 'sso')); // 'sso' | 'ldap' | 'local'

// ── SSO ──────────────────────────────────────────────────────
// URL de votre portail SSO (SAML). Le module SSO/authSSO.php
// lit également SSO/config — les deux doivent être cohérents.
define('SSO_URL',    env('CRYPTEX_SSO_URL',    'https://sso.example.com/saml/index.php'));
define('SSO_LOGOUT', env('CRYPTEX_SSO_LOGOUT', 'https://sso.example.com/saml/logoutSSO.php'));

// ── LDAP / Active Directory ───────────────────────────────────
// Utilisé pour la recherche d'utilisateurs ET pour
// l'authentification si AUTH_LDAP_ENABLED = true.
define('LDAP_HOST',      env('CRYPTEX_LDAP_HOST',      'ldap://ad.example.com'));
define('LDAP_PORT',      envInt('CRYPTEX_LDAP_PORT',    389));
define('LDAP_BASE_DN',   env('CRYPTEX_LDAP_BASE_DN',   'DC=example,DC=com'));
// Compte de service en lecture seule (laisser vide pour bind anonyme)
define('LDAP_BIND_USER', env('CRYPTEX_LDAP_BIND_USER', ''));
define('LDAP_BIND_PASS', env('CRYPTEX_LDAP_BIND_PASS', ''));
// UPN suffix pour l'auth LDAP : login@LDAP_DOMAIN → bind sur l'AD
define('LDAP_DOMAIN',    env('CRYPTEX_LDAP_DOMAIN',    'example.com'));

// Recherche AD dans les formulaires (autocomplétion destinataire)
// Peut être true même si AUTH_LDAP_ENABLED = false
define('LDAP_SEARCH_ENABLED', envBool('CRYPTEX_LDAP_SEARCH', true));

// ── Base de données ───────────────────────────────────────────
// 'sqlite' (aucun serveur requis, recommandé pour test/small)
// 'mysql'  (MariaDB/MySQL, recommandé en production)
define('DB_DRIVER', env('CRYPTEX_DB_DRIVER', 'sqlite'));

define('DB_PATH',   env('CRYPTEX_DB_PATH', __DIR__ . '/data/cryptex.db'));

define('DB_HOST',   env('CRYPTEX_DB_HOST', 'db'));
define('DB_PORT',   envInt('CRYPTEX_DB_PORT', 3306));
define('DB_NAME',   env('CRYPTEX_DB_NAME', 'cryptex'));
define('DB_USER',   env('CRYPTEX_DB_USER', 'cryptex'));
define('DB_PASS',   env('CRYPTEX_DB_PASS', 'changeme'));

// ── Clé de chiffrement AES-256 ────────────────────────────────
// ⚠️  OBLIGATOIRE : 32 caractères minimum, aléatoire et unique.
// En production, NE PAS laisser la valeur par défaut.
define('ENCRYPTION_KEY', env('CRYPTEX_ENCRYPTION_KEY', 'CHANGE_ME_32_CHARS_MINIMUM_RANDOM'));

// ── Application ───────────────────────────────────────────────
define('APP_NAME',    env('CRYPTEX_APP_NAME',    'Cryptex'));
define('APP_URL',     env('CRYPTEX_APP_URL',     'https://cryptex.example.com'));
define('APP_VERSION', '1.0.0');

// ── Email ─────────────────────────────────────────────────────
define('MAIL_HOST',      env('CRYPTEX_MAIL_HOST',      'smtp.example.com'));
define('MAIL_PORT',      envInt('CRYPTEX_MAIL_PORT',    25));
define('MAIL_FROM',      env('CRYPTEX_MAIL_FROM',      'cryptex@example.com'));
define('MAIL_FROM_NAME', env('CRYPTEX_MAIL_FROM_NAME', 'Cryptex'));
define('MAIL_SMTP_AUTH', envBool('CRYPTEX_MAIL_AUTH',  false));
define('MAIL_USERNAME',  env('CRYPTEX_MAIL_USER',      ''));
define('MAIL_PASSWORD',  env('CRYPTEX_MAIL_PASS',      ''));

// ── Durées disponibles (heures) ───────────────────────────────
define('DURATIONS', [
    1   => '1 heure',
    4   => '4 heures',
    8   => '8 heures',
    24  => '24 heures',
    72  => '3 jours',
    168 => '7 jours',
    336 => '14 jours',
]);

// ── Image / branding ─────────────────────────────────────────
define('MAIL_LOGO_URL', APP_URL . '/img/logo.png');

// ── Sécurité ─────────────────────────────────────────────────
define('MAX_SECRET_SIZE',  10000);   // caractères max
define('TOKEN_LENGTH',     32);      // bytes
define('SESSION_LIFETIME', envInt('CRYPTEX_SESSION_LIFETIME', 3600));

// ── Rétention audit (jours) ───────────────────────────────────
define('AUDIT_RETENTION_DAYS', envInt('CRYPTEX_AUDIT_DAYS', 90));

// ── Pièces jointes ───────────────────────────────────────────
define('FILE_STORAGE_PATH', env('CRYPTEX_FILE_PATH', __DIR__ . '/data/files'));
define('MAX_FILE_SIZE',    envInt('CRYPTEX_MAX_FILE_SIZE', 10 * 1024 * 1024));
define('MAX_FILES_COUNT',  envInt('CRYPTEX_MAX_FILES',     5));
define('ALLOWED_EXTENSIONS', [
    'pdf','txt','csv','log',
    'jpg','jpeg','png','gif','webp','svg',
    'doc','docx','xls','xlsx','ppt','pptx','odt','ods','odp',
    'zip','7z','tar','gz',
    'xml','json','yaml','yml',
    'p12','pem','crt','key',
]);

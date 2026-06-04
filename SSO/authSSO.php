<?php
session_start();
// Charger config.php (constantes globales) puis SSO/config (variables $linkSSO, etc.)
require_once __DIR__ . '/../config.php';
require __DIR__ . '/config';

/* 1️⃣ Si le SSO renvoie le token sécurisé */
if (isset($_GET['sso_token']) && !isset($_SESSION['attributes'])) {
    $token = $_GET['sso_token'];
    if (!ctype_xdigit($token) || strlen($token) !== 64) { die("Token SSO invalide."); }

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => 'Content-Type: application/x-www-form-urlencoded',
            'content' => http_build_query(['sso_token' => $token]),
            'timeout' => 5,
        ],
        'ssl' => [
            'verify_peer'      => false,
            'verify_peer_name' => false,
        ]
    ]);
    // L'URL de token_exchange est déduite de SSO_URL (défini dans config.php)
    // Exemple : https://sso.exemple.fr/saml/index.php → https://sso.exemple.fr/saml/token_exchange.php
    $tokenExchangeUrl = rtrim(dirname(SSO_URL), '/') . '/token_exchange.php';

    $response = @file_get_contents($tokenExchangeUrl, false, $ctx);
    if ($response === false) { die("Impossible de joindre le serveur SSO ({$tokenExchangeUrl})."); }

    $data = json_decode($response, true);
    if (empty($data['ok'])) { die("Échec SSO : " . htmlspecialchars($data['error'] ?? 'Erreur inconnue')); }

    $_SESSION['user']       = $data['user'];
    $_SESSION['attributes'] = $data['attributes'];  // clé cohérente avec auth.php

    $queryParams = $_GET;
    unset($queryParams['sso_token']);
    $cleanPath = strtok($_SERVER['REQUEST_URI'], '?');
    $cleanUrl  = $cleanPath . (!empty($queryParams) ? '?' . http_build_query($queryParams) : '');
    header("Location: " . $cleanUrl);
    exit;
}

/* 2️⃣ Si les attributs existent on prépare les variables */
if (isset($_SESSION['attributes'])) {

    $attributes = $_SESSION['attributes'];

    function attr($attributes, $key) {
        return $attributes[$key][0] ?? "Non renseigné";
    }

    $_SESSION['prenom_sso']        = attr($attributes, "http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname");
    $_SESSION['nom_sso']           = attr($attributes, "http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname");
    $_SESSION['mail_sso']          = attr($attributes, "http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress");
    $_SESSION['service_sso']       = attr($attributes, "department");
    $_SESSION['poste_sso']         = attr($attributes, "jobtitle");
    $_SESSION['telephone_sso']     = attr($attributes, "telephone");
    $_SESSION['mobile_sso']        = attr($attributes, "mobile");
    $_SESSION['matricule_sso']     = attr($attributes, "employeenumber");
    $_SESSION['site_sso']          = attr($attributes, "officelocation");
    $_SESSION['hiredate_sso']      = attr($attributes, "hiredate");
    $_SESSION['name_sso']          = attr($attributes, "http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name");
    $_SESSION['samaccountname_sso']= attr($attributes, "samaccountname");
}

/* 3️⃣ Construction URL de retour pour le bouton SSO */
$protocol   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
$returnUrl  = urlencode($protocol . "://" . $_SERVER['HTTP_HOST'] . strtok($_SERVER['REQUEST_URI'], '?'));
$ssoLoginUrl = $linkSSO . "?return=" . $returnUrl;
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars(APP_NAME) ?> — Connexion SSO</title>
    <style>
        * { margin:0; padding:0; box-sizing:border-box; font-family: Arial, sans-serif; }
        body { background: #f7f7f7; display: flex; justify-content: center; align-items: center; height: 100vh; }
        .login-container { background: #ffffff; padding: 40px; border-radius: 12px; box-shadow: 0 8px 20px rgba(0,0,0,0.15); text-align: center; width: 400px; }
        .login-container img { max-width: 150px; margin-bottom: 30px; }
        h1 { color: #005a9c; margin-bottom: 20px; font-size: 24px; }
        p { color: #333333; margin-bottom: 10px; }
        .info { margin-top: 20px; background: #e6f0fa; padding: 15px; border-radius: 8px; color: #005a9c; text-align: left; }
        .info p { margin: 5px 0; }
        button { margin-top: 20px; padding: 10px 20px; background-color: #005a9c; color: #ffffff; border: none; border-radius: 8px; cursor: pointer; font-size: 16px; }
        button:hover { background-color: #004080; }
    </style>
</head>
<body>
<div class="login-container">

<img src="img/logo.png" alt="<?= htmlspecialchars(APP_NAME) ?>">

<?php if (!isset($_SESSION['attributes'])): ?>

    <h1>Connexion requise</h1>
    <p>Veuillez vous connecter via le SSO</p>
    <form action="<?php echo $ssoLoginUrl; ?>" method="post">
        <button type="submit">Connexion SSO</button>
    </form>

<?php else: ?>

    <h1>Bienvenue, <?php echo htmlspecialchars($_SESSION['prenom_sso']); ?></h1>
    <p>Vous êtes connecté à <?php echo htmlspecialchars($appliName); ?></p>
    <form action="<?php echo htmlspecialchars($linkto); ?>" method="post">
        <button type="submit">Poursuivre vers <?php echo htmlspecialchars($appliName); ?></button>
    </form>
    <form action="logoutSSO.php" method="post">
        <button type="submit">Se déconnecter</button>
    </form>

<?php endif; ?>

</div>
</body>
</html>

<?php
// ============================================================
//  CRYPTEX — API : Recherche destinataires (table users)
//  Remplace la recherche LDAP directe — plus rapide et fiable
//  Exclut automatiquement les utilisateurs bloqués
// ============================================================

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';

startSession(); // Utilise les mêmes paramètres que auth.php (SameSite=Lax)

header('Content-Type: application/json; charset=UTF-8');
header('X-Content-Type-Options: nosniff');

// Authentification requise
if (!isset($_SESSION['attributes'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Non authentifié']);
    exit;
}

// Requêtes AJAX uniquement
if (strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'xmlhttprequest') {
    http_response_code(403);
    echo json_encode(['error' => 'Requête non autorisée']);
    exit;
}

$q = trim($_GET['q'] ?? '');

if (strlen($q) < 2) {
    echo json_encode([]);
    exit;
}

echo json_encode(searchUsers($q, 12));

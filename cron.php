#!/usr/bin/env php
<?php
// ============================================================
//  CRYPTEX — Tâche planifiée : nettoyage automatique
//
//  Crontab (exemple, toutes les heures) :
//  0 * * * * /usr/bin/php /var/www/cryptex/cron.php >> /var/log/cryptex-cron.log 2>&1
// ============================================================

define('CRON_MODE', true);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/crypto.php';

$start = microtime(true);
$log   = function(string $msg) { echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL; };

$log('Cryptex CRON démarré');

// 1. Marquer comme détruits les secrets expirés
$purged = purgeExpired();
$log("Secrets expirés détruits : {$purged}");

// 2. Supprimer les fichiers chiffrés des secrets détruits
try {
    $purgedFiles = purgeExpiredFiles();
    $log("Fichiers chiffrés supprimés : {$purgedFiles}");
} catch (Throwable $e) {
    $log('ERREUR suppression fichiers : ' . $e->getMessage());
}

// 3. Nettoyage du journal d'audit (rétention définie dans config)
try {
    $db = getDB();

    if (DB_DRIVER === 'mysql') {
        $stmt = $db->prepare("
            DELETE FROM audit_log
            WHERE created_at < DATE_SUB(NOW(), INTERVAL :days DAY)
        ");
    } else {
        $stmt = $db->prepare("
            DELETE FROM audit_log
            WHERE created_at < datetime('now', '-' || :days || ' days')
        ");
    }

    $stmt->execute([':days' => AUDIT_RETENTION_DAYS]);
    $purgedLogs = $stmt->rowCount();
    $log("Entrées d'audit supprimées : {$purgedLogs}");

} catch (Throwable $e) {
    $log('ERREUR audit_log : ' . $e->getMessage());
}

// 4. Optimisation (SQLite uniquement — MySQL gère ça nativement)
if (DB_DRIVER === 'sqlite' && date('G') === '3') {
    try {
        getDB()->exec('VACUUM');
        $log('VACUUM SQLite effectué');
    } catch (Throwable $e) {
        $log('ERREUR VACUUM : ' . $e->getMessage());
    }
}

$elapsed = round((microtime(true) - $start) * 1000);
$log("CRON terminé en {$elapsed} ms");
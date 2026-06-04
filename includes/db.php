<?php
// ============================================================
//  CRYPTEX — Base de données v2 (SQLite / MySQL)
//  Nouveau schéma : users + secret_recipients multi-dest.
// ============================================================

function getDB(): PDO {
    static $db = null;
    if ($db === null) {
        $db = (DB_DRIVER === 'mysql') ? connectMySQL() : connectSQLite();
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        initSchema($db);
        runMigrations($db);
    }
    return $db;
}

function connectSQLite(): PDO {
    $dir = dirname(DB_PATH);
    if (!is_dir($dir)) mkdir($dir, 0750, true);
    $db = new PDO('sqlite:' . DB_PATH);
    $db->exec("PRAGMA journal_mode=WAL");
    $db->exec("PRAGMA foreign_keys=ON");
    return $db;
}

function connectMySQL(): PDO {
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4;collation=utf8mb4_unicode_ci', DB_HOST, DB_PORT, DB_NAME);
    return new PDO($dsn, DB_USER, DB_PASS, [
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00'",
    ]);
}

function now(): string {
    return (DB_DRIVER === 'mysql') ? 'NOW()' : "datetime('now')";
}

// ── Migration : ajoute les colonnes manquantes sans toucher aux données ──
function runMigrations(PDO $db): void {
    if (DB_DRIVER === 'mysql') {
        // Vérifie colonne par colonne et ajoute si absente
        $migrations = [
            ['table' => 'secrets', 'column' => 'is_globally_destroyed',
             'sql'   => 'ALTER TABLE secrets ADD COLUMN is_globally_destroyed TINYINT(1) NOT NULL DEFAULT 0'],
            ['table' => 'secrets', 'column' => 'destroy_on_read',
             'sql'   => 'ALTER TABLE secrets ADD COLUMN destroy_on_read TINYINT(1) NOT NULL DEFAULT 0'],
            ['table' => 'users',   'column' => 'samaccountname',
             'sql'   => 'ALTER TABLE users ADD COLUMN samaccountname VARCHAR(100)'],
            ['table' => 'users',   'column' => 'source',
             'sql'   => "ALTER TABLE users ADD COLUMN source VARCHAR(20) NOT NULL DEFAULT 'sso'"],
            ['table' => 'users',   'column' => 'first_login',
             'sql'   => 'ALTER TABLE users ADD COLUMN first_login DATETIME'],
            ['table' => 'users',   'column' => 'last_login',
             'sql'   => 'ALTER TABLE users ADD COLUMN last_login DATETIME'],
        ];

        // Normaliser la collation des tables existantes (MySQL 8 vs ancienne création)
        $tablesToFix = ['users','secrets','secret_recipients','audit_log'];
        foreach ($tablesToFix as $tbl) {
            try {
                $db->exec("ALTER TABLE `{$tbl}` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            } catch (Throwable) {}
        }

        foreach ($migrations as $m) {
            try {
                $check = $db->prepare(
                    "SELECT COUNT(*) FROM information_schema.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_NAME   = :t
                       AND COLUMN_NAME  = :c"
                );
                $check->execute([':t' => $m['table'], ':c' => $m['column']]);
                if ((int)$check->fetchColumn() === 0) {
                    $db->exec($m['sql']);
                }
            } catch (Throwable) { /* colonne déjà présente ou table absente — OK */ }
        }

        // Créer secret_files si elle n'existe pas encore
        try {
            $db->exec("
                CREATE TABLE IF NOT EXISTS `secret_files` (
                    id                  INT AUTO_INCREMENT PRIMARY KEY,
                    secret_id           INT NOT NULL,
                    filename_original   VARCHAR(255) NOT NULL,
                    filename_stored     VARCHAR(64)  NOT NULL,
                    mime_type           VARCHAR(127) NOT NULL DEFAULT 'application/octet-stream',
                    file_size           INT NOT NULL DEFAULT 0,
                    file_nonce          VARCHAR(64)  NOT NULL,
                    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (secret_id) REFERENCES secrets(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (Throwable $e) { error_log('[Cryptex] secret_files create error: ' . $e->getMessage()); }

        // Créer secret_recipients si elle n'existe pas encore
        try {
            $db->exec("
                CREATE TABLE IF NOT EXISTS secret_recipients (
                    id               INT AUTO_INCREMENT PRIMARY KEY,
                    secret_id        INT NOT NULL,
                    token            VARCHAR(128) UNIQUE NOT NULL,
                    recipient_email  VARCHAR(200) NOT NULL,
                    recipient_name   VARCHAR(200),
                    read_at          DATETIME,
                    is_destroyed     TINYINT(1) NOT NULL DEFAULT 0,
                    FOREIGN KEY (secret_id) REFERENCES secrets(id) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (Throwable) {}

        // Migrer les anciennes lignes de secrets vers secret_recipients
        try {
            $hasToken = $db->query(
                "SELECT COUNT(*) FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='secrets' AND COLUMN_NAME='token'"
            )->fetchColumn();

            if ($hasToken) {
                // Copier les anciens enregistrements qui n'ont pas encore de recipient
                $db->exec("
                    INSERT IGNORE INTO secret_recipients
                        (secret_id, token, recipient_email, recipient_name, read_at, is_destroyed)
                    SELECT s.id, s.token, s.recipient_email, s.recipient_name,
                           s.read_at, s.is_destroyed
                    FROM   secrets s
                    WHERE  s.token IS NOT NULL
                      AND  NOT EXISTS (
                          SELECT 1 FROM secret_recipients r WHERE r.secret_id = s.id
                      )
                ");
            }
        } catch (Throwable) {}

    } else {
        // SQLite — même logique via PRAGMA table_info
        $tables = [];
        foreach ($db->query("SELECT name FROM sqlite_master WHERE type='table'") as $r) {
            $tables[] = $r['name'];
        }

        if (in_array('secrets', $tables)) {
            $cols = array_column(
                $db->query("PRAGMA table_info(secrets)")->fetchAll(), 'name'
            );
            if (!in_array('is_globally_destroyed', $cols)) {
                $db->exec("ALTER TABLE secrets ADD COLUMN is_globally_destroyed INTEGER NOT NULL DEFAULT 0");
            }
            if (!in_array('destroy_on_read', $cols)) {
                $db->exec("ALTER TABLE secrets ADD COLUMN destroy_on_read INTEGER NOT NULL DEFAULT 0");
            }
        }

        if (in_array('users', $tables)) {
            $cols = array_column(
                $db->query("PRAGMA table_info(users)")->fetchAll(), 'name'
            );
            if (!in_array('source', $cols)) {
                $db->exec("ALTER TABLE users ADD COLUMN source TEXT NOT NULL DEFAULT 'sso'");
            }
            if (!in_array('first_login', $cols)) {
                $db->exec("ALTER TABLE users ADD COLUMN first_login TEXT");
            }
            if (!in_array('last_login', $cols)) {
                $db->exec("ALTER TABLE users ADD COLUMN last_login TEXT");
            }
        }

        // Créer secret_files si absente
        $db->exec("
            CREATE TABLE IF NOT EXISTS secret_files (
                id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                secret_id           INTEGER NOT NULL,
                filename_original   TEXT NOT NULL,
                filename_stored     TEXT NOT NULL,
                mime_type           TEXT NOT NULL DEFAULT 'application/octet-stream',
                file_size           INTEGER NOT NULL DEFAULT 0,
                file_nonce          TEXT NOT NULL,
                created_at          TEXT NOT NULL DEFAULT (datetime('now')),
                FOREIGN KEY (secret_id) REFERENCES secrets(id) ON DELETE CASCADE
            )
        ");

        // Créer secret_recipients si absente
        $db->exec("
            CREATE TABLE IF NOT EXISTS secret_recipients (
                id               INTEGER PRIMARY KEY AUTOINCREMENT,
                secret_id        INTEGER NOT NULL,
                token            TEXT UNIQUE NOT NULL,
                recipient_email  TEXT NOT NULL,
                recipient_name   TEXT,
                read_at          TEXT,
                is_destroyed     INTEGER NOT NULL DEFAULT 0,
                FOREIGN KEY (secret_id) REFERENCES secrets(id) ON DELETE CASCADE
            )
        ");

        // Migrer anciens enregistrements si colonne token existe dans secrets
        if (in_array('secrets', $tables)) {
            $cols = array_column(
                $db->query("PRAGMA table_info(secrets)")->fetchAll(), 'name'
            );
            if (in_array('token', $cols)) {
                $db->exec("
                    INSERT OR IGNORE INTO secret_recipients
                        (secret_id, token, recipient_email, recipient_name, read_at, is_destroyed)
                    SELECT s.id, s.token, s.recipient_email, s.recipient_name,
                           s.read_at, s.is_destroyed
                    FROM   secrets s
                    WHERE  s.token IS NOT NULL
                      AND  NOT EXISTS (
                          SELECT 1 FROM secret_recipients r WHERE r.secret_id = s.id
                      )
                ");
            }
        }
    }
}

// ── Schéma ────────────────────────────────────────────────────
function initSchema(PDO $db): void {
    if (DB_DRIVER === 'mysql') {
        $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id               INT AUTO_INCREMENT PRIMARY KEY,
            samaccountname   VARCHAR(100) UNIQUE NOT NULL,
            email            VARCHAR(200) UNIQUE NOT NULL,
            nom              VARCHAR(100),
            prenom           VARCHAR(100),
            service          VARCHAR(200),
            poste            VARCHAR(200),
            site             VARCHAR(200),
            telephone        VARCHAR(50),
            role             ENUM('admin','user','blocked') NOT NULL DEFAULT 'user',
            source           ENUM('sso','ad_sync') NOT NULL DEFAULT 'sso',
            first_login      DATETIME,
            last_login       DATETIME,
            created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS secrets (
            id                  INT AUTO_INCREMENT PRIMARY KEY,
            content_encrypted   MEDIUMTEXT NOT NULL,
            nonce               VARCHAR(64) NOT NULL,
            title               VARCHAR(200),
            sender_name         VARCHAR(200) NOT NULL,
            sender_email        VARCHAR(200) NOT NULL,
            created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at          DATETIME NOT NULL,
            destroy_on_read     TINYINT(1) NOT NULL DEFAULT 0,
            is_globally_destroyed TINYINT(1) NOT NULL DEFAULT 0,
            ip_sender           VARCHAR(64)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS secret_recipients (
            id               INT AUTO_INCREMENT PRIMARY KEY,
            secret_id        INT NOT NULL,
            token            VARCHAR(128) UNIQUE NOT NULL,
            recipient_email  VARCHAR(200) NOT NULL,
            recipient_name   VARCHAR(200),
            read_at          DATETIME,
            is_destroyed     TINYINT(1) NOT NULL DEFAULT 0,
            FOREIGN KEY (secret_id) REFERENCES secrets(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS audit_log (
            id         INT AUTO_INCREMENT PRIMARY KEY,
            token      VARCHAR(128),
            action     VARCHAR(100) NOT NULL,
            actor      VARCHAR(200),
            ip         VARCHAR(64),
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

        CREATE TABLE IF NOT EXISTS secret_files (
            id                  INT AUTO_INCREMENT PRIMARY KEY,
            secret_id           INT NOT NULL,
            filename_original   VARCHAR(255) NOT NULL COMMENT 'Nom original du fichier',
            filename_stored     VARCHAR(64)  NOT NULL COMMENT 'Nom UUID sur disque (.enc)',
            mime_type           VARCHAR(127) NOT NULL DEFAULT 'application/octet-stream',
            file_size           INT          NOT NULL DEFAULT 0 COMMENT 'Taille en octets (avant chiffrement)',
            file_nonce          VARCHAR(64)  NOT NULL COMMENT 'Nonce GCM base64',
            created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (secret_id) REFERENCES secrets(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    } else {
        // SQLite
        $db->exec("
        CREATE TABLE IF NOT EXISTS users (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            samaccountname   TEXT UNIQUE NOT NULL,
            email            TEXT UNIQUE NOT NULL,
            nom              TEXT,
            prenom           TEXT,
            service          TEXT,
            poste            TEXT,
            site             TEXT,
            telephone        TEXT,
            role             TEXT NOT NULL DEFAULT 'user',
            source           TEXT NOT NULL DEFAULT 'sso',
            first_login      TEXT,
            last_login       TEXT NOT NULL DEFAULT (datetime('now')),
            created_at       TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at       TEXT NOT NULL DEFAULT (datetime('now'))
        );

        CREATE TABLE IF NOT EXISTS secrets (
            id                    INTEGER PRIMARY KEY AUTOINCREMENT,
            content_encrypted     TEXT NOT NULL,
            nonce                 TEXT NOT NULL,
            title                 TEXT,
            sender_name           TEXT NOT NULL,
            sender_email          TEXT NOT NULL,
            created_at            TEXT NOT NULL DEFAULT (datetime('now')),
            expires_at            TEXT NOT NULL,
            destroy_on_read       INTEGER NOT NULL DEFAULT 0,
            is_globally_destroyed INTEGER NOT NULL DEFAULT 0,
            ip_sender             TEXT
        );

        CREATE TABLE IF NOT EXISTS secret_recipients (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            secret_id        INTEGER NOT NULL,
            token            TEXT UNIQUE NOT NULL,
            recipient_email  TEXT NOT NULL,
            recipient_name   TEXT,
            read_at          TEXT,
            is_destroyed     INTEGER NOT NULL DEFAULT 0,
            FOREIGN KEY (secret_id) REFERENCES secrets(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS audit_log (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            token      TEXT,
            action     TEXT NOT NULL,
            actor      TEXT,
            ip         TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime('now'))
        );

        CREATE TABLE IF NOT EXISTS secret_files (
            id                  INTEGER PRIMARY KEY AUTOINCREMENT,
            secret_id           INTEGER NOT NULL,
            filename_original   TEXT NOT NULL,
            filename_stored     TEXT NOT NULL,
            mime_type           TEXT NOT NULL DEFAULT 'application/octet-stream',
            file_size           INTEGER NOT NULL DEFAULT 0,
            file_nonce          TEXT NOT NULL,
            created_at          TEXT NOT NULL DEFAULT (datetime('now')),
            FOREIGN KEY (secret_id) REFERENCES secrets(id) ON DELETE CASCADE
        );
        ");
    }
}

// ── Utilisateurs ──────────────────────────────────────────────

function registerOrUpdateUser(array $ssoData): array {
    $db    = getDB();
    $email = strtolower(trim($ssoData['email']));
    $login = strtolower(trim($ssoData['samaccountname'] ?? $email));
    $n     = now();

    // CONVERT ... COLLATE : fonctionne quelle que soit la collation de la colonne
    $col = (DB_DRIVER === 'mysql')
        ? "CONVERT(%s USING utf8mb4) COLLATE utf8mb4_unicode_ci"
        : "%s";

    $stmt = $db->prepare(sprintf("
        SELECT * FROM users
        WHERE  {$col} = :e
           OR  {$col} = :l
        LIMIT 1
    ", 'email', 'samaccountname'));
    $stmt->execute([':e' => $email, ':l' => $login]);
    $user = $stmt->fetch();

    if ($user) {
        $upd = $db->prepare("
            UPDATE users
            SET nom=:nom, prenom=:prenom, service=:service,
                poste=:poste, site=:site, telephone=:tel,
                last_login=$n, updated_at=$n
            WHERE id=:id
        ");
        $upd->execute([
            ':nom' => $ssoData['nom'], ':prenom' => $ssoData['prenom'],
            ':service' => $ssoData['service'], ':poste' => $ssoData['poste'],
            ':site' => $ssoData['site'] ?? null, ':tel' => $ssoData['telephone'] ?? null,
            ':id' => $user['id'],
        ]);
        return getUserByEmail($email);
    }

    // Nouvelle inscription
    $ins = $db->prepare("
        INSERT INTO users
            (samaccountname, email, nom, prenom, service, poste, site, telephone,
             role, source, first_login, last_login)
        VALUES (:login,:email,:nom,:prenom,:service,:poste,:site,:tel,
                'user','sso',$n,$n)
    ");
    $ins->execute([
        ':login' => $login, ':email' => $email,
        ':nom' => $ssoData['nom'], ':prenom' => $ssoData['prenom'],
        ':service' => $ssoData['service'], ':poste' => $ssoData['poste'],
        ':site' => $ssoData['site'] ?? null, ':tel' => $ssoData['telephone'] ?? null,
    ]);
    return getUserByEmail($email);
}

function getUserByEmail(string $email): ?array {
    $db    = getDB();
    $email = strtolower(trim($email));
    $col   = (DB_DRIVER === 'mysql')
        ? "CONVERT(email USING utf8mb4) COLLATE utf8mb4_unicode_ci"
        : "email";
    $stmt  = $db->prepare("SELECT * FROM users WHERE $col = :e");
    $stmt->execute([':e' => $email]);
    return $stmt->fetch() ?: null;
}


function getAllUsers(string $filter = ''): array {
    $db  = getDB();
    $sql = "SELECT u.*,
                (SELECT COUNT(*) FROM secrets WHERE sender_email COLLATE utf8mb4_unicode_ci=u.email) as secrets_sent,
                (SELECT COUNT(*) FROM secret_recipients WHERE recipient_email COLLATE utf8mb4_unicode_ci=u.email) as secrets_received
            FROM users u";
    if ($filter && in_array($filter, ['admin','user','blocked'])) {
        $sql .= " WHERE u.role = :role";
    }
    $sql .= " ORDER BY u.nom, u.prenom";

    $stmt = $db->prepare($sql);
    if ($filter && in_array($filter, ['admin','user','blocked'])) {
        $stmt->execute([':role' => $filter]);
    } else {
        $stmt->execute();
    }
    return $stmt->fetchAll();
}

function setUserRole(int $userId, string $role): bool {
    if (!in_array($role, ['admin','user','blocked'])) return false;
    $db   = getDB();
    $n    = now();
    $stmt = $db->prepare("UPDATE users SET role=:role, updated_at=$n WHERE id=:id");
    $stmt->execute([':role' => $role, ':id' => $userId]);
    return true;
}

function upsertADUser(array $ad): void {
    $db    = getDB();
    $email = strtolower(trim($ad['email']));
    $login = strtolower(trim($ad['login'] ?? $email));
    $n     = now();

    $stmt = $db->prepare("SELECT id, source FROM users WHERE email COLLATE utf8mb4_unicode_ci=:e");
    $stmt->execute([':e' => $email]);
    $existing = $stmt->fetch();

    if ($existing) {
        // Ne pas écraser le rôle ni la source SSO
        $db->prepare("
            UPDATE users SET
                nom=:nom, prenom=:prenom, service=:service,
                poste=:poste, site=:site, telephone=:tel,
                updated_at=$n
            WHERE id=:id
        ")->execute([
            ':nom'=>$ad['nom'],':prenom'=>$ad['prenom'],
            ':service'=>$ad['service'],':poste'=>$ad['poste'],
            ':site'=>$ad['site']??null,':tel'=>$ad['telephone']??null,
            ':id'=>$existing['id'],
        ]);
    } else {
        $db->prepare("
            INSERT OR IGNORE INTO users
                (samaccountname,email,nom,prenom,service,poste,site,telephone,role,source,last_login)
            VALUES
                (:l,:e,:nom,:prenom,:svc,:poste,:site,:tel,'user','ad_sync',$n)
        ")->execute([
            ':l'=>$login,':e'=>$email,':nom'=>$ad['nom'],':prenom'=>$ad['prenom'],
            ':svc'=>$ad['service'],':poste'=>$ad['poste'],
            ':site'=>$ad['site']??null,':tel'=>$ad['telephone']??null,
        ]);
    }
}

// ── Secrets ───────────────────────────────────────────────────

function createSecret(array $data): array {
    $db = getDB();
    $n  = now();

    // Insérer le secret (contenu chiffré partagé)
    $stmt = $db->prepare("
        INSERT INTO secrets
            (content_encrypted, nonce, title, sender_name, sender_email,
             expires_at, destroy_on_read, ip_sender)
        VALUES (:ce,:nonce,:title,:sn,:se,:ea,:dor,:ip)
    ");
    $stmt->execute([
        ':ce'    => $data['content_encrypted'],
        ':nonce' => $data['nonce'],
        ':title' => $data['title'] ?? null,
        ':sn'    => $data['sender_name'],
        ':se'    => $data['sender_email'],
        ':ea'    => $data['expires_at'],
        ':dor'   => $data['destroy_on_read'] ? 1 : 0,
        ':ip'    => $data['ip_sender'] ?? null,
    ]);
    $secretId = (int)$db->lastInsertId();

    // Créer un token par destinataire
    $tokens = [];
    foreach ($data['recipients'] as $recipient) {
        $token = bin2hex(random_bytes(TOKEN_LENGTH));
        $db->prepare("
            INSERT INTO secret_recipients
                (secret_id, token, recipient_email, recipient_name)
            VALUES (:sid,:token,:email,:name)
        ")->execute([
            ':sid'   => $secretId,
            ':token' => $token,
            ':email' => strtolower(trim($recipient['email'])),
            ':name'  => $recipient['name'] ?? null,
        ]);
        auditLog($token, 'created', $data['sender_email'], $data['ip_sender'] ?? null);
        $tokens[] = ['token' => $token, 'email' => $recipient['email'], 'name' => $recipient['name'] ?? null];
    }
    return ['secret_id' => $secretId, 'recipients' => $tokens];
}

function getSecretByToken(string $token): ?array {
    $db   = getDB();
    $n    = now();
    $stmt = $db->prepare("
        SELECT s.*, sr.id as recipient_id, sr.token, sr.recipient_email,
               sr.recipient_name, sr.read_at, sr.is_destroyed as recipient_destroyed
        FROM   secret_recipients sr
        JOIN   secrets s ON s.id = sr.secret_id
        WHERE  sr.token = :t
          AND  sr.is_destroyed = 0
          AND  s.is_globally_destroyed = 0
          AND  s.expires_at > $n
    ");
    $stmt->execute([':t' => $token]);
    return $stmt->fetch() ?: null;
}

function markRead(string $token): void {
    $db = getDB();
    $n  = now();
    // Récupérer destroy_on_read depuis le secret parent
    $stmt = $db->prepare("
        SELECT s.destroy_on_read FROM secret_recipients sr
        JOIN secrets s ON s.id=sr.secret_id WHERE sr.token=:t
    ");
    $stmt->execute([':t' => $token]);
    $row = $stmt->fetch();
    $destroy = $row ? (int)$row['destroy_on_read'] : 0;

    $db->prepare("
        UPDATE secret_recipients
        SET read_at=$n, is_destroyed=:d
        WHERE token=:t
    ")->execute([':d' => $destroy, ':t' => $token]);
}

function purgeExpired(): int {
    $db = getDB();
    $n  = now();
    // Marquer les secrets expirés comme globally_destroyed
    $stmt = $db->prepare("
        UPDATE secrets SET is_globally_destroyed=1
        WHERE is_globally_destroyed=0 AND expires_at<=$n
    ");
    $stmt->execute();
    return $stmt->rowCount();
}

function auditLog(string $token, string $action, ?string $actor, ?string $ip): void {
    $db = getDB();
    $db->prepare("INSERT INTO audit_log (token,action,actor,ip) VALUES (:t,:a,:ac,:ip)")
       ->execute([':t'=>$token,':a'=>$action,':ac'=>$actor,':ip'=>$ip]);
}

function getStats(): array {
    $db = getDB();
    $n  = now();
    return [
        'total'    => (int)$db->query("SELECT COUNT(*) FROM secrets")->fetchColumn(),
        'active'   => (int)$db->query("SELECT COUNT(*) FROM secrets WHERE is_globally_destroyed=0 AND expires_at>$n")->fetchColumn(),
        'read'     => (int)$db->query("SELECT COUNT(*) FROM secret_recipients WHERE read_at IS NOT NULL")->fetchColumn(),
        'expired'  => (int)$db->query("SELECT COUNT(*) FROM secrets WHERE is_globally_destroyed=1")->fetchColumn(),
        'users'    => (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn(),
        'blocked'  => (int)$db->query("SELECT COUNT(*) FROM users WHERE role='blocked'")->fetchColumn(),
    ];
}

// ── Recherche destinataires depuis la table users ─────────────
// Exclut les utilisateurs bloqués (role = 'blocked')
function searchUsers(string $query, int $limit = 12): array {
    $db   = getDB();
    $q    = '%' . trim($query) . '%';

    $stmt = $db->prepare("
        SELECT prenom, nom, email, service, poste
        FROM   users
        WHERE  role IN ('admin', 'user')
          AND  (
              prenom  LIKE :q1 OR
              nom     LIKE :q2 OR
              email   LIKE :q3 OR
              service LIKE :q4 OR
              samaccountname LIKE :q5
          )
        ORDER BY nom, prenom
        LIMIT  :limit
    ");
    $stmt->bindValue(':q1', $q);
    $stmt->bindValue(':q2', $q);
    $stmt->bindValue(':q3', $q);
    $stmt->bindValue(':q4', $q);
    $stmt->bindValue(':q5', $q);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return array_map(fn($r) => [
        'name'       => trim(($r['prenom'] ?? '') . ' ' . ($r['nom'] ?? '')),
        'email'      => $r['email'],
        'department' => $r['service'] ?? '',
        'jobtitle'   => $r['poste']   ?? '',
    ], $stmt->fetchAll());
}

// ── Historique des envois ─────────────────────────────────────
function getSentHistory(string $senderEmail, bool $activeOnly = true): array {
    $db  = getDB();
    $n   = now();
    $emailCol = (DB_DRIVER === 'mysql') ? "CONVERT(s.sender_email USING utf8mb4) COLLATE utf8mb4_unicode_ci" : "s.sender_email";
    $sql = "
        SELECT s.id, s.title, s.created_at, s.expires_at, s.destroy_on_read,
               sr.token, sr.recipient_email, sr.recipient_name,
               sr.read_at, sr.is_destroyed as recipient_destroyed
        FROM   secrets s
        JOIN   secret_recipients sr ON sr.secret_id = s.id
        WHERE  " . $emailCol . " = :email
          AND  s.is_globally_destroyed = 0
    ";
    if ($activeOnly) {
        $sql .= " AND s.expires_at > $n AND sr.is_destroyed = 0";
    }
    $sql .= " ORDER BY s.created_at DESC";

    $stmt = $db->prepare($sql);
    $stmt->execute([':email' => strtolower(trim($senderEmail))]);
    $rows = $stmt->fetchAll();

    // Grouper par secret (un secret → N destinataires)
    $grouped = [];
    foreach ($rows as $r) {
        $sid = $r['id'];
        if (!isset($grouped[$sid])) {
            $grouped[$sid] = [
                'id'             => $sid,
                'title'          => $r['title'],
                'created_at'     => $r['created_at'],
                'expires_at'     => $r['expires_at'],
                'destroy_on_read'=> (int)$r['destroy_on_read'],
                'recipients'     => [],
            ];
        }
        $grouped[$sid]['recipients'][] = [
            'token'      => $r['token'],
            'email'      => $r['recipient_email'],
            'name'       => $r['recipient_name'],
            'read_at'    => $r['read_at'],
            'destroyed'  => (int)$r['recipient_destroyed'],
        ];
    }
    return array_values($grouped);
}

function getSecretForResend(string $token, string $senderEmail): ?array {
    $db   = getDB();
    $n    = now();
    $stmt = $db->prepare("
        SELECT s.title, s.expires_at, s.destroy_on_read, s.sender_name,
               sr.token, sr.recipient_email, sr.recipient_name
        FROM   secret_recipients sr
        JOIN   secrets s ON s.id = sr.secret_id
        WHERE  sr.token     = :token
          AND  s.sender_email = :email
          AND  s.is_globally_destroyed = 0
          AND  s.expires_at > $n
          AND  sr.is_destroyed = 0
    ");
    $stmt->execute([':token' => $token, ':email' => strtolower(trim($senderEmail))]);
    return $stmt->fetch() ?: null;
}

// ── Suppression d'un envoi par l'expéditeur ───────────────────
function deleteRecipientToken(string $token, string $senderEmail): bool {
    $db       = getDB();
    $email    = strtolower(trim($senderEmail));
    $tokenCol = (DB_DRIVER === 'mysql')
        ? "CONVERT(sr.token USING utf8mb4) COLLATE utf8mb4_unicode_ci"
        : "sr.token";
    $emailCol = (DB_DRIVER === 'mysql')
        ? "CONVERT(s.sender_email USING utf8mb4) COLLATE utf8mb4_unicode_ci"
        : "s.sender_email";

    // Récupérer l'id (PK entier) pour éviter tout problème de collation dans l'UPDATE
    $stmt = $db->prepare("
        SELECT sr.id, sr.secret_id
        FROM   secret_recipients sr
        JOIN   secrets s ON s.id = sr.secret_id
        WHERE  $tokenCol = :token
          AND  $emailCol = :email
          AND  sr.is_destroyed = 0
    ");
    $stmt->execute([':token' => $token, ':email' => $email]);
    $row = $stmt->fetch();
    if (!$row) return false;

    // UPDATE sur id (entier) — aucune ambiguïté de collation
    $upd = $db->prepare("UPDATE secret_recipients SET is_destroyed = 1 WHERE id = :id");
    $upd->execute([':id' => (int)$row['id']]);

    if ($upd->rowCount() === 0) return false; // rien mis à jour

    // Si tous les destinataires du secret sont détruits → détruire aussi le parent
    $rem = $db->prepare("SELECT COUNT(*) FROM secret_recipients WHERE secret_id=:sid AND is_destroyed=0");
    $rem->execute([':sid' => $row['secret_id']]);
    if ((int)$rem->fetchColumn() === 0) {
        $db->prepare("UPDATE secrets SET is_globally_destroyed=1 WHERE id=:sid")
           ->execute([':sid' => $row['secret_id']]);
    }

    auditLog($token, 'deleted_by_sender', $email, null);
    return true;
}

// ── Réceptions : secrets reçus par le destinataire connecté ──
function getReceivedSecrets(string $recipientEmail): array {
    $db    = getDB();
    $n     = now();
    $email = strtolower(trim($recipientEmail));
    $col   = (DB_DRIVER === 'mysql')
        ? "CONVERT(sr.recipient_email USING utf8mb4) COLLATE utf8mb4_unicode_ci"
        : "sr.recipient_email";

    $stmt = $db->prepare("
        SELECT s.id, s.title, s.sender_name, s.sender_email,
               s.created_at, s.expires_at, s.destroy_on_read,
               sr.token, sr.read_at,
               sr.is_destroyed as recipient_destroyed,
               CASE WHEN s.expires_at > $n AND sr.is_destroyed = 0 THEN 1 ELSE 0 END as is_active
        FROM   secret_recipients sr
        JOIN   secrets s ON s.id = sr.secret_id
        WHERE  $col = :email
          AND  s.is_globally_destroyed = 0
        ORDER BY s.created_at DESC
        LIMIT  100
    ");
    $stmt->execute([':email' => $email]);
    return $stmt->fetchAll();
}

// ── Pièces jointes ────────────────────────────────────────────

function attachFileToSecret(int $secretId, array $fileData): int {
    $db   = getDB();
    $stmt = $db->prepare("
        INSERT INTO secret_files
            (secret_id, filename_original, filename_stored, mime_type, file_size, file_nonce)
        VALUES (:sid, :forig, :fstored, :mime, :size, :nonce)
    ");
    $stmt->execute([
        ':sid'     => $secretId,
        ':forig'   => $fileData['filename_original'],
        ':fstored' => $fileData['filename_stored'],
        ':mime'    => $fileData['mime_type'],
        ':size'    => $fileData['file_size'],
        ':nonce'   => $fileData['file_nonce'],
    ]);
    return (int)$db->lastInsertId();
}

function getSecretFiles(int $secretId): array {
    $db   = getDB();
    $stmt = $db->prepare("SELECT * FROM secret_files WHERE secret_id = :sid ORDER BY id");
    $stmt->execute([':sid' => $secretId]);
    return $stmt->fetchAll();
}

function getFileForDownload(int $fileId, string $token): ?array {
    $db   = getDB();
    $n    = now();
    // Vérifie que le fichier appartient au secret lié au token,
    // que l'utilisateur est bien destinataire, et que le secret est toujours valide.
    $stmt = $db->prepare("
        SELECT sf.*, sr.recipient_email, s.is_globally_destroyed, s.expires_at
        FROM   secret_files sf
        JOIN   secrets s           ON s.id  = sf.secret_id
        JOIN   secret_recipients sr ON sr.secret_id = sf.secret_id
        WHERE  sf.id   = :fid
          AND  sr.token = :token
          AND  s.is_globally_destroyed = 0
          AND  s.expires_at > $n
    ");
    $stmt->execute([':fid' => $fileId, ':token' => $token]);
    return $stmt->fetch() ?: null;
}

function purgeExpiredFiles(): int {
    $db      = getDB();
    $deleted = 0;
    // Récupérer les fichiers des secrets expirés/détruits
    $stmt = $db->query("
        SELECT sf.filename_stored
        FROM   secret_files sf
        JOIN   secrets s ON s.id = sf.secret_id
        WHERE  s.is_globally_destroyed = 1
    ");
    foreach ($stmt->fetchAll() as $row) {
        $path = FILE_STORAGE_PATH . '/' . basename($row['filename_stored']);
        if (file_exists($path)) {
            unlink($path);
            $deleted++;
        }
    }
    return $deleted;
}

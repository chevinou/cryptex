# 🔐 Cryptex

> Transmission sécurisée d'informations confidentielles — alternative open source à [One-Time Secret](https://onetimesecret.com)

[![Licence: AGPL v3](https://img.shields.io/badge/Licence-AGPL%20v3-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-777bb4)](https://www.php.net)
[![Docker](https://img.shields.io/badge/Docker-ready-2496ed)](https://hub.docker.com)

---

## Présentation

**Cryptex** permet de partager des informations sensibles (mots de passe, tokens, clés d'accès, certificats…) de façon sécurisée, **sans les envoyer en clair par email**.

L'expéditeur dépose son secret, désigne un ou plusieurs destinataires, et Cryptex leur envoie un **lien unique chiffré**. Le secret est détruit automatiquement à expiration ou après lecture.

### Principes de sécurité

| Mécanisme | Détail |
|-----------|--------|
| Chiffrement | AES-256-GCM — le contenu n'est jamais stocké en clair |
| Authentification | SSO/SAML, Active Directory ou comptes locaux (modulaire) |
| Contrôle d'accès | Seul le destinataire désigné peut consulter |
| Expiration | Destruction automatique (cron) ou après première lecture |
| Audit | Journal complet de toutes les actions |
| Emails | Contiennent uniquement un lien, jamais le secret |
| CSRF | Token sur tous les formulaires POST |
| Injections | PDO préparé (SQL), échappement RFC-4515 (LDAP) |

---

## Fonctionnalités

- Chiffrement AES-256-GCM des secrets et pièces jointes
- Modes d'authentification modulaires : **SSO/SAML**, **Active Directory**, **comptes locaux**
- Autocomplétion des destinataires via annuaire LDAP/AD
- Pièces jointes chiffrées (PDF, images, archives, certificats…)
- Durée de vie paramétrable (1h → 14 jours)
- Option "lecture unique" (destruction immédiate après consultation)
- Multi-destinataires avec suivi individuel
- Boîte de réception et historique des secrets envoyés
- Interface d'administration (gestion des rôles, audit)
- Déploiement classique (LAMP/LEMP) ou conteneurisé (Docker)
- Support SQLite (petit déploiement) ou MySQL/MariaDB (production)

---

## Architecture

```
cryptex/
├── config.php              ← Configuration principale (⚠ adapter)
├── login.php               ← Page de connexion multi-modes
├── index.php               ← Dépôt d'un secret (expéditeur)
├── view.php                ← Consultation d'un secret (destinataire)
├── inbox.php               ← Boîte de réception
├── history.php             ← Historique des envois
├── admin.php               ← Interface d'administration
├── logout.php              ← Déconnexion
├── cron.php                ← Nettoyage automatique (secrets expirés)
├── download.php            ← Téléchargement de pièces jointes
├── .htaccess               ← Sécurité Apache
├── assets/                 ← CSS + JavaScript
├── api/
│   └── search_users.php    ← API autocomplétion LDAP (AJAX)
├── includes/
│   ├── auth.php            ← Middleware d'authentification (multi-modes)
│   ├── crypto.php          ← Chiffrement AES-256-GCM
│   ├── db.php              ← Abstraction BDD (SQLite / MySQL)
│   ├── ldap.php            ← Recherche Active Directory
│   └── mail.php            ← Envoi d'emails + templates
├── SSO/
│   ├── authSSO.php         ← Module SSO/SAML
│   ├── barSSO.php          ← Barre info utilisateur SSO
│   ├── config              ← Config SSO (lue depuis config.php)
│   └── logoutSSO.php       ← Déconnexion SSO
├── data/                   ← BDD SQLite + fichiers chiffrés (⚠ hors web)
├── docker-compose.yml      ← Déploiement Docker
├── Dockerfile
└── .env.example            ← Template de configuration Docker
```

---

## Déploiement rapide — Docker

### Prérequis

- Docker Engine ≥ 24
- Docker Compose ≥ 2.20

### 1. Cloner et configurer

```bash
git clone https://github.com/chevinou/cryptex.git
cd cryptex
cp .env.example .env
```

Éditez `.env` et configurez **au minimum** :

```dotenv
ENCRYPTION_KEY=   # openssl rand -hex 32
APP_URL=          # https://cryptex.votre-domaine.fr
AUTH_SSO=true     # ou false si vous n'avez pas de SSO
```

### 2. Lancer (SQLite — recommandé pour commencer)

```bash
docker compose up -d
```

### Creer un utilisateur

docker exec -it cryptex_app php -r "
require '/var/www/html/config.php';
require '/var/www/html/includes/db.php';
createLocalUser([
    'login'    => 'admin',
    'password' => 'MotDePasseTest123!',
    'email'    => 'admin@exemple.fr',
    'nom'      => 'Dupont',
    'prenom'   => 'Jean',
    'service'  => 'Informatique',
    'poste'    => 'Administrateur',
]);
echo 'Utilisateur créé avec succès.' . PHP_EOL;
"

Cryptex est disponible sur [http://localhost:8080](http://localhost:8080).

### 3. Lancer avec MySQL/MariaDB

```bash
# Dans .env : DB_DRIVER=mysql  +  DB_PASS=...
docker compose --profile mysql up -d
```

PHPMyAdmin (mode debug uniquement) :
```bash
docker compose --profile mysql --profile debug up -d
# Disponible sur http://localhost:8081
```

### 4. Configurer le cron (si non Docker)

```bash
crontab -e -u www-data
# Ajouter :
0 * * * * /usr/bin/php /var/www/cryptex/cron.php >> /var/log/cryptex-cron.log 2>&1
```

En mode Docker, le cron est intégré à l'image.

---

## Déploiement classique — LAMP/LEMP

### Prérequis serveur

| Composant | Version minimale | Modules PHP requis |
|-----------|------------------|--------------------|
| PHP | 8.0+ | openssl, pdo, pdo_mysql ou pdo_sqlite, ldap, mbstring |
| Apache | 2.4+ | mod_rewrite, mod_headers |
| Nginx | 1.18+ | php-fpm |
| MySQL/MariaDB | 10.6+ | (optionnel, SQLite par défaut) |

```bash
# Debian / Ubuntu
apt install php php-openssl php-pdo php-sqlite3 php-mysql php-ldap php-mbstring
a2enmod rewrite headers expires
systemctl restart apache2
```

### Installation

```bash
# 1. Copier les fichiers
cp -r cryptex/ /var/www/cryptex
chown -R www-data:www-data /var/www/cryptex
chmod 750 /var/www/cryptex/data
chmod 640 /var/www/cryptex/config.php

# 2. Initialiser la BDD MySQL (si DB_DRIVER=mysql)
mysql -u root -p < /var/www/cryptex/cryptex.sql

# 3. Configurer Apache
# → Voir exemple de VirtualHost ci-dessous
```

### VirtualHost Apache (HTTPS recommandé)

```apache
<VirtualHost *:443>
    ServerName cryptex.votre-domaine.fr
    DocumentRoot /var/www/cryptex

    SSLEngine on
    SSLCertificateFile    /etc/ssl/certs/cryptex.crt
    SSLCertificateKeyFile /etc/ssl/private/cryptex.key

    <Directory /var/www/cryptex>
        AllowOverride All
        Require all granted
    </Directory>

    <Directory /var/www/cryptex/data>
        Require all denied
    </Directory>
</VirtualHost>
```

---

## Configuration

### Fichier `config.php`

Toutes les options sont documentées dans le fichier. En mode Docker, elles peuvent être surchargées par des variables d'environnement (préfixe `CRYPTEX_`).

#### Module d'authentification

Cryptex supporte trois modes d'authentification, activables indépendamment :

```php
// Au moins un mode doit être true
define('AUTH_SSO_ENABLED',   true);   // Portail SSO/SAML
define('AUTH_LDAP_ENABLED',  false);  // Active Directory (login + mot de passe)
define('AUTH_LOCAL_ENABLED', false);  // Comptes locaux Cryptex

// Mode affiché par défaut sur la page de connexion
define('AUTH_DEFAULT_MODE', 'sso'); // 'sso' | 'ldap' | 'local'
```

En Docker, via `.env` :

```dotenv
AUTH_SSO=true
AUTH_LDAP=false
AUTH_LOCAL=false
AUTH_DEFAULT=sso
```

#### SSO / SAML

```php
define('SSO_URL',    'https://sso.votre-domaine.fr/saml/index.php');
define('SSO_LOGOUT', 'https://sso.votre-domaine.fr/saml/logoutSSO.php');
```

Le fichier `SSO/config` est généré automatiquement depuis ces constantes — il n'est plus nécessaire de le modifier séparément.

#### Active Directory / LDAP

```php
define('LDAP_HOST',      'ldap://ad.votre-domaine.local');
define('LDAP_PORT',      389);
define('LDAP_BASE_DN',   'DC=votre-domaine,DC=local');
define('LDAP_BIND_USER', 'CN=svc-cryptex,OU=Services,DC=votre-domaine,DC=local');
define('LDAP_BIND_PASS', 'mot-de-passe-compte-service');
define('LDAP_DOMAIN',    'votre-domaine.local'); // suffix UPN pour l'auth LDAP
```

Le mode LDAP permet deux usages indépendants :
- **Autocomplétion des destinataires** (`LDAP_SEARCH_ENABLED`) — fonctionne quel que soit le mode d'auth
- **Authentification AD** (`AUTH_LDAP_ENABLED`) — l'utilisateur saisit son login/mot de passe Windows

#### Comptes locaux

Lorsque `AUTH_LOCAL_ENABLED = true`, Cryptex utilise la table `local_users` (BDD Cryptex).
La création de comptes se fait depuis l'interface d'administration.
Les mots de passe sont hashés avec bcrypt.

---

## Flux fonctionnel

```
Expéditeur (authentifié) → index.php
   ↓ Saisit : secret + destinataire(s) + durée
   ↓ Cryptex chiffre AES-256-GCM + génère token unique
   ↓ Stocke en BDD (jamais en clair)
   ↓ Envoie email avec lien sécurisé

Destinataire → reçoit email → clique sur le lien
   ↓ view.php?t=<token>
   ↓ Authentification si non connecté
   ↓ Vérification : email connecté === email destinataire
   ↓ Déchiffrement + affichage
   ↓ Destruction (lecture unique) ou à expiration
```

---

## Sécurité — Guide de durcissement

En production, vérifiez les points suivants :

- [ ] `ENCRYPTION_KEY` : valeur aléatoire forte (`openssl rand -hex 32`), changée par rapport à la valeur par défaut
- [ ] `config.php` inaccessible depuis le web (protégé par `.htaccess` / Nginx)
- [ ] Répertoire `data/` inaccessible depuis le web
- [ ] HTTPS activé avec certificat valide
- [ ] Cron configuré pour le nettoyage automatique
- [ ] Le fichier `.env` n'est pas accessible publiquement
- [ ] Le fichier `.env` n'est pas commité sur Git (vérifié dans `.gitignore`)

---

## Contribution

Les contributions sont les bienvenues ! Merci de :

1. Ouvrir une **Issue** avant de démarrer un développement important
2. Créer une branche depuis `main` : `git checkout -b feature/ma-fonctionnalite`
3. Respecter les conventions de code existantes
4. Tester sur PHP 8.0+, SQLite et MySQL
5. Ouvrir une **Pull Request** avec une description claire

Consultez [CONTRIBUTING.md](CONTRIBUTING.md) pour les détails.

---

## Licence

Cryptex est distribué sous licence **GNU Affero General Public License v3.0 (AGPL-3.0)**.

Cela signifie :

- ✅ Utilisation gratuite, y compris en production
- ✅ Modification et redistribution libres
- ✅ Utilisation comme service en ligne (SaaS)
- ⚠️ Toute modification redistribuée **doit** être publiée sous AGPL-3.0
- ⚠️ Si vous proposez Cryptex comme service en ligne, vous **devez** publier vos modifications
- ❌ Revente du logiciel sans publication du code source modifié

Voir [LICENSE](LICENSE) pour le texte complet.

---

## Support

Ouvrez une [Issue GitHub](../../issues) pour tout rapport de bug ou demande de fonctionnalité.

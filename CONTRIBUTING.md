# Contribuer a Cryptex

Merci de l'interet que vous portez a Cryptex !

## Avant de commencer

- Verifiez les Issues existantes avant d'en ouvrir une nouvelle.
- Pour un bug mineur (typo, petit correctif) : une Pull Request directe est la bienvenue.
- Pour une nouvelle fonctionnalite ou un changement d'architecture : ouvrez d'abord une Issue pour en discuter avant de coder.

## Signaler un bug

Ouvrez une Issue en precisnant :
- La version de Cryptex et de PHP
- Le mode de deploiement (Docker, LAMP, LEMP)
- Le mode d'authentification utilise (SSO, LDAP, local)
- Les etapes pour reproduire le probleme
- Le comportement attendu vs le comportement observe
- Les logs d'erreur si disponibles

Ne publiez jamais de donnees sensibles (cle de chiffrement, mots de passe, tokens) dans une Issue.

## Proposer une fonctionnalite

Ouvrez une Issue en decrivant :
- Le probleme que vous cherchez a resoudre
- La solution envisagee
- Les alternatives que vous avez considerees

## Soumettre une Pull Request

### 1. Forker et preparer votre branche

```bash
git clone https://github.com/votre-fork/cryptex.git
cd cryptex
git checkout -b feature/ma-fonctionnalite   # ou fix/mon-correctif
```

### 2. Conventions de code

- PHP 8.0+ minimum, indentation 4 espaces
- Toute nouvelle fonction documentee par un commentaire
- Pas de dependances externes sans discussion prealable (Cryptex est volontairement sans Composer)
- Aucun secret ou credential dans le code : utiliser config.php ou les variables d'environnement

### 3. Tester avant de soumettre

```bash
cp .env.example .env   # editer ENCRYPTION_KEY et APP_URL
docker compose up -d
```

Verifiez que votre changement fonctionne sur :
- PHP 8.0+ (minimum requis)
- SQLite (deploiement par defaut)
- MySQL/MariaDB si le changement touche a la base de donnees

### 4. Ouvrir la Pull Request

Remplissez le template avec :
- Une description claire du changement
- La reference a l'Issue associee (ex : Closes #42)
- Des screenshots si le changement touche l'interface

## Ce qui ne sera pas accepte

- Code qui affaiblit la securite (desactivation du CSRF, stockage en clair, etc.)
- Dependances tierces lourdes sans justification
- Changements cassant la compatibilite PHP 8.0
- Donnees d'organisations reelles dans les exemples ou la documentation

## Code de conduite

Soyez respectueux et constructif dans vos echanges.
Les contributions irrespecteuses envers les autres membres seront ignorees.

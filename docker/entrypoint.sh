#!/bin/bash
# ============================================================
#  CRYPTEX — Entrypoint Docker
#  Initialise les dossiers et lance Apache + cron.
# ============================================================
set -e

# Dossier data (SQLite + fichiers chiffrés)
mkdir -p /var/www/html/data/files
chown -R www-data:www-data /var/www/html/data
chmod 750 /var/www/html/data
chmod 750 /var/www/html/data/files

# Démarrer le cron daemon
service cron start

echo "✅  Cryptex démarré — $(date)"

# Lancer la commande passée en argument (apache2-foreground par défaut)
exec "$@"

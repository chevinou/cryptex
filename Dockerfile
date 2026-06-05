# ============================================================
#  CRYPTEX — Dockerfile
# ============================================================

FROM php:8.3-apache

LABEL maintainer="Cryptex contributors"
LABEL description="Cryptex — Transmission sécurisée d'informations confidentielles"

# ── Extensions PHP ────────────────────────────────────────────
RUN apt-get update && apt-get install -y --no-install-recommends \
        libldap2-dev \
        libssl-dev \
        libzip-dev \
        libsqlite3-dev \
        unzip \
        cron \
    && docker-php-ext-configure ldap \
    && docker-php-ext-install \
        ldap \
        pdo \
        pdo_mysql \
        pdo_sqlite \
        zip \
        opcache \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && rm -rf /var/lib/apt/lists/*

# ── Apache ────────────────────────────────────────────────────
RUN a2enmod rewrite headers expires ssl

COPY docker/apache/cryptex.conf /etc/apache2/sites-available/000-default.conf
COPY docker/php/cryptex.ini /usr/local/etc/php/conf.d/cryptex.ini

# ── Cron ─────────────────────────────────────────────────────
RUN echo "0 * * * * www-data php /var/www/html/cron.php >> /var/log/cryptex-cron.log 2>&1" \
    > /etc/cron.d/cryptex && chmod 0644 /etc/cron.d/cryptex

# ── Application ───────────────────────────────────────────────
WORKDIR /var/www/html
COPY . .

RUN mkdir -p data/files \
    && chown -R www-data:www-data /var/www/html \
    && chmod 750 /var/www/html/data \
    && [ -f /var/www/html/config.php ] && chmod 640 /var/www/html/config.php || true \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && find /var/www/html/data -type d -exec chmod 750 {} \;

VOLUME ["/var/www/html/data"]
EXPOSE 80 443

# ── Entrypoint inline (évite les problèmes de CRLF Windows) ──
RUN printf '#!/bin/sh\nmkdir -p /var/www/html/data/files\nchown -R www-data:www-data /var/www/html/data\nservice cron start\nexec "$@"\n' > /entrypoint.sh \
    && chmod +x /entrypoint.sh

ENTRYPOINT ["/entrypoint.sh"]
CMD ["apache2-foreground"]

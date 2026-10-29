# Razvojna slika: PHP 8.1 CLI sa ekstenzijama koje panel koristi (pdo_mysql, pdo_sqlite, zip, sodium) i Composer-om.
# Koristi se samo za testove i build na računaru bez lokalnog PHP-a (vidi tools/dev/php i tools/dev/composer).
FROM php:8.1-cli
RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev unzip git \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_HOME=/tmp/composer COMPOSER_ALLOW_SUPERUSER=1
WORKDIR /app

# Simulacija shared hostinga: Apache 2.4 + mod_php 8.1, mod_rewrite, AllowOverride All, PHP radi kao www-data.
FROM php:8.1-apache
RUN apt-get update \
    && apt-get install -y --no-install-recommends libzip-dev \
    && docker-php-ext-install -j"$(nproc)" pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/* \
    && a2enmod rewrite headers \
    && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
    && printf 'memory_limit=128M\nupload_max_filesize=16M\npost_max_size=16M\nexpose_php=Off\n' > /usr/local/etc/php/conf.d/hosting.ini \
    && mkdir -p /var/www/html/panel && chown www-data:www-data /var/www/html/panel

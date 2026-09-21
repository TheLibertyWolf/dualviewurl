FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev libxml2-dev libsqlite3-dev \
    && docker-php-ext-install curl dom pdo_sqlite \
    && a2enmod headers rewrite \
    && rm -rf /var/lib/apt/lists/*

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' \
    /etc/apache2/sites-available/*.conf /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

COPY --chown=www-data:www-data . /var/www/html

RUN mkdir -p /var/lib/dualviewurl \
    && chown www-data:www-data /var/lib/dualviewurl \
    && chmod 750 /var/lib/dualviewurl \
    && find /var/www/html -type d -exec chmod 755 {} + \
    && find /var/www/html -type f -exec chmod 644 {} + \
    && sed -ri 's#CustomLog (.*) combined#CustomLog \1 combined env=!dontlog#' /etc/apache2/sites-available/*.conf \
    && sed -i '$aServerName dualviewurl-app' /etc/apache2/apache2.conf

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD curl -fsS http://127.0.0.1/health.php || exit 1

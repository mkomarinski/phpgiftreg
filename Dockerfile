FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libpng-dev \
        libjpeg-dev \
        libfreetype6-dev \
        zip \
        unzip \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql gd \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

COPY src/ /var/www/html/

# Copy database schema for initialization (optional)
COPY src/sql/create-phpgiftregdb.sql /docker-entrypoint-initdb.d/

# Copy entrypoint script
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

RUN mkdir -p /var/www/html/item_images \
    && mkdir -p /var/www/html/templates_c \
    && mkdir -p /var/www/html/cache \
    && chmod -R 755 /var/www/html \
    && chmod -R 775 /var/www/html/item_images \
    && chmod -R 775 /var/www/html/templates_c \
    && chmod -R 775 /var/www/html/cache \
    && chown -R www-data:www-data /var/www/html

EXPOSE 80

ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]

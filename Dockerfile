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

RUN mkdir -p /var/www/html/item_images \
    && chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

EXPOSE 80

CMD ["apache2-foreground"]

#!/bin/bash
set -e

# Create required directories if they don't exist (needed due to volume mounts)
mkdir -p /var/www/html/item_images
mkdir -p /var/www/html/templates_c
mkdir -p /var/www/html/cache

# Fix ownership of mounted volumes for www-data
chown -R www-data:www-data /var/www/html
chmod -R 755 /var/www/html
chmod -R 775 /var/www/html/item_images
chmod -R 775 /var/www/html/templates_c
chmod -R 775 /var/www/html/cache

# Start Apache
exec apache2-foreground

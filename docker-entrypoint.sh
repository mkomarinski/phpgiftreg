#!/bin/bash
set -e

# Fix ownership of mounted volumes for www-data
chown -R www-data:www-data /var/www/html
chmod -R 755 /var/www/html
chmod -R 775 /var/www/html/item_images
chmod -R 775 /var/www/html/templates_c
chmod -R 775 /var/www/html/cache

# Start Apache
exec apache2-foreground

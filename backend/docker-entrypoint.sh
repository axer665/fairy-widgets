#!/bin/sh
set -e
mkdir -p /var/www/html/storage/media
# Bind-mount с хоста: каталог должен быть доступен www-data (Apache)
chown -R www-data:www-data /var/www/html/storage 2>/dev/null || chmod -R a+rwx /var/www/html/storage
exec "$@"

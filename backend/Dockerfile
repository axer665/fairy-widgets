FROM php:8.3-apache-bookworm

RUN a2enmod rewrite headers setenvif \
  && docker-php-ext-install pdo_mysql

COPY docker-php-uploads.ini /usr/local/etc/php/conf.d/99-uploads.ini

COPY apache-vhost.conf /etc/apache2/sites-available/000-default.conf
WORKDIR /var/www/html
COPY . /var/www/html

RUN chown -R www-data:www-data /var/www/html

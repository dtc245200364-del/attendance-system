FROM php:8.3-apache
RUN docker-php-ext-install pdo_mysql \
    && sed -i 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf \
    && sed -i 's/:80>/:8080>/' /etc/apache2/sites-available/000-default.conf \
    && printf 'ServerTokens Prod\nServerSignature Off\n' > /etc/apache2/conf-available/privacy.conf \
    && a2enconf privacy
COPY docker/php.ini /usr/local/etc/php/conf.d/attendance.ini
COPY app/ /var/www/html/
USER www-data
EXPOSE 8080

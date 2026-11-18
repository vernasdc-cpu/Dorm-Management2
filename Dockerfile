FROM php:8.2-apache

COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN apt-get update && apt-get install -y default-mysql-client libzip-dev libpng-dev libjpeg-dev libwebp-dev && \
    docker-php-ext-configure gd --with-jpeg --with-webp && \
    docker-php-ext-install pdo pdo_mysql mysqli zip gd && \
    a2enmod rewrite headers && rm -rf /var/lib/apt/lists/*

WORKDIR /opt/dorm-dependencies
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-progress --optimize-autoloader
WORKDIR /var/www/html

# Apache docroot = /var/www/html ; copy app
COPY . /var/www/html/
COPY docker/apache-private.conf /etc/apache2/conf-available/private-references.conf
RUN a2enconf private-references

# Upload dirs writable
RUN mkdir -p /var/www/html/uploads/contracts /var/www/html/uploads/repairs /var/www/html/uploads/profiles \
 && chown -R www-data:www-data /var/www/html/uploads \
 && chmod -R 775 /var/www/html/uploads

COPY docker/entrypoint.sh /usr/local/bin/app-entrypoint.sh
RUN chmod +x /usr/local/bin/app-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["app-entrypoint.sh"]
CMD ["apache2-foreground"]

FROM php:8.2-apache

# Install PHP extensions for MySQL + uploads
RUN docker-php-ext-install pdo pdo_mysql mysqli && \
    a2enmod rewrite headers && \
    apt-get update && apt-get install -y default-mysql-client && rm -rf /var/lib/apt/lists/*

# Apache docroot = /var/www/html ; copy app
COPY . /var/www/html/

# Upload dirs writable
RUN mkdir -p /var/www/html/uploads/contracts /var/www/html/uploads/repairs /var/www/html/uploads/profiles \
 && chown -R www-data:www-data /var/www/html/uploads \
 && chmod -R 775 /var/www/html/uploads

COPY docker/entrypoint.sh /usr/local/bin/app-entrypoint.sh
RUN chmod +x /usr/local/bin/app-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["app-entrypoint.sh"]
CMD ["apache2-foreground"]


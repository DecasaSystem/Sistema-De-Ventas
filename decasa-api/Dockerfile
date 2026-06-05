FROM php:8.4-apache

RUN apt-get update && apt-get install -y \
    git unzip libpng-dev libjpeg-dev libfreetype6-dev libzip-dev libavif-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-avif \
    && docker-php-ext-install -j$(nproc) gd pdo_mysql bcmath zip pcntl \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Aumentar límites de subida de archivos (default PHP = 2M, insuficiente para fotos)
RUN echo "upload_max_filesize = 20M\npost_max_size = 25M\nmemory_limit = 256M\nmax_execution_time = 60" \
    > /usr/local/etc/php/conf.d/uploads.ini

RUN a2enmod rewrite proxy proxy_http proxy_wstunnel

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html
COPY . .

RUN composer install --no-dev --optimize-autoloader

RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R 775 storage bootstrap/cache

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/000-default.conf /etc/apache2/conf-available/docker-php.conf

RUN sed -i '/<\/VirtualHost>/i\    ProxyPass /app ws://127.0.0.1:8080/app\n    ProxyPassReverse /app ws://127.0.0.1:8080/app' /etc/apache2/sites-available/000-default.conf

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]

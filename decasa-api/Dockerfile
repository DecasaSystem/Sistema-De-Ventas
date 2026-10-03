FROM php:8.4-apache

RUN apt-get update && apt-get install -y \
    git unzip libpng-dev libjpeg-dev libfreetype6-dev libzip-dev libavif-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-avif \
    && docker-php-ext-install -j$(nproc) gd pdo_mysql bcmath zip pcntl opcache \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# OPcache: sin esto PHP vuelve a leer y compilar los cientos de archivos de
# Laravel en CADA petición. Con él quedan compilados en memoria.
#
# validate_timestamps=0: no revisa si los archivos cambiaron. En el contenedor
# el código no cambia nunca —cada despliegue es una imagen nueva que arranca
# con la caché vacía—, así que revisarlo sería trabajo tirado.
RUN { \
      echo 'opcache.enable=1'; \
      echo 'opcache.enable_cli=0'; \
      echo 'opcache.memory_consumption=128'; \
      echo 'opcache.interned_strings_buffer=16'; \
      echo 'opcache.max_accelerated_files=20000'; \
      echo 'opcache.validate_timestamps=0'; \
      echo 'realpath_cache_size=4096K'; \
      echo 'realpath_cache_ttl=600'; \
    } > /usr/local/etc/php/conf.d/opcache.ini

# Aumentar límites de subida de archivos (default PHP = 2M, insuficiente para fotos).
# Una entrega lleva fotos por cada producto (hasta 15) más comprobantes, firma y
# anexo: max_file_uploads (20 por defecto) se quedaba corto, y subirlas a
# Cloudinary una por una necesita más de 60 segundos en el peor caso.
RUN echo "upload_max_filesize = 20M\npost_max_size = 60M\nmax_file_uploads = 40\nmemory_limit = 256M\nmax_execution_time = 120" \
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

FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev libzip-dev poppler-utils \
    && docker-php-ext-install pdo pdo_mysql mbstring zip \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY public/ /var/www/html/

RUN php -l /var/www/html/index.php \
    && php -l /var/www/html/source_processor.php \
    && php -l /var/www/html/course_engine.php \
    && php -l /var/www/html/factory.php \
    && php -l /var/www/html/cidades_inclusivas_model.php \
    && php -l /var/www/html/cidades_inclusivas.php \
    && php -l /var/www/html/diagnostic.php \
    && php -l /var/www/html/health.php \
    && chown -R www-data:www-data /var/www/html

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
  CMD php -r '$c=@file_get_contents("http://127.0.0.1/health.php"); exit($c === "OK\n" ? 0 : 1);'

FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libcurl4-openssl-dev libonig-dev libzip-dev poppler-utils tesseract-ocr tesseract-ocr-por \
    && docker-php-ext-install pdo pdo_mysql mbstring zip curl \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY public/ /var/www/html/

RUN find /var/www/html -name "*.php" -exec php -l {} + \
    && chown -R www-data:www-data /var/www/html

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
  CMD php -r '$c=@file_get_contents("http://127.0.0.1/health.php"); exit($c === "OK\n" ? 0 : 1);'

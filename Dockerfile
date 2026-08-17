FROM php:8.3-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends libonig-dev libzip-dev poppler-utils \
    && docker-php-ext-install pdo pdo_mysql mbstring zip \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /var/www/html

COPY public/ /var/www/html/

RUN php -l /var/www/html/index.php \
    && php -l /var/www/html/dashboard.php \
    && php -l /var/www/html/homologacao_visual.php \
    && php -l /var/www/html/source_processor.php \
    && php -l /var/www/html/course_engine.php \
    && php -l /var/www/html/factory.php \
    && php -l /var/www/html/cidades_inclusivas_model.php \
    && php -l /var/www/html/cidades_inclusivas.php \
    && php -l /var/www/html/academic_model.php \
    && php -l /var/www/html/academic_eligibility.php \
    && php -l /var/www/html/academic_rules.php \
    && php -l /var/www/html/assessments.php \
    && php -l /var/www/html/academic_completion.php \
    && php -l /var/www/html/academic.php \
    && php -l /var/www/html/student_progress.php \
    && php -l /var/www/html/student_portal_model.php \
    && php -l /var/www/html/aluno_login.php \
    && php -l /var/www/html/aluno.php \
    && php -l /var/www/html/aula.php \
    && php -l /var/www/html/progress_api.php \
    && php -l /var/www/html/portal_access_admin.php \
    && php -l /var/www/html/turmas_presenciais.php \
    && php -l /var/www/html/validar_certificado.php \
    && php -l /var/www/html/certificado.php \
    && php -l /var/www/html/diagnostic.php \
    && php -l /var/www/html/health.php \
    && chown -R www-data:www-data /var/www/html

EXPOSE 80

HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
  CMD php -r '$c=@file_get_contents("http://127.0.0.1/health.php"); exit($c === "OK\n" ? 0 : 1);'

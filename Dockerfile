FROM php:8.3-cli-bookworm

ARG OPENSWOOLE_VERSION=
ARG UOPZ_VERSION=

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    ZEALPHP_HOST=0.0.0.0 \
    ZEALPHP_PORT=8080

COPY setup.sh /tmp/zealphp-setup.sh
RUN bash /tmp/zealphp-setup.sh --docker && rm /tmp/zealphp-setup.sh

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader

COPY . .

EXPOSE 8080

CMD ["php", "app.php"]

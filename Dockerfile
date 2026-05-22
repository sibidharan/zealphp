# Pinned by digest for supply-chain reproducibility (OpenSSF Scorecard
# Pinned-Dependencies). Dependabot's docker ecosystem bumps this digest weekly
# so security patches keep flowing — see .github/dependabot.yml.
FROM php:8.5-cli-bookworm@sha256:f1c3261b0926b2f7c5a0cb4d7c9e48f43a07c15425edbe311427b1df1e529280

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

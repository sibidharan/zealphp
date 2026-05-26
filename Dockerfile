# Pinned by digest for supply-chain reproducibility (OpenSSF Scorecard
# Pinned-Dependencies). Dependabot's docker ecosystem bumps this digest weekly
# so security patches keep flowing — see .github/dependabot.yml.
FROM php:8.3-cli-bookworm@sha256:ed0bdc6dad3aea67fe296d9eea65a11b89677c1cbcf304601a630f4d318be308

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

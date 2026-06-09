# Pinned by digest for supply-chain reproducibility (OpenSSF Scorecard
# Pinned-Dependencies). Dependabot's docker ecosystem bumps this digest weekly
# so security patches keep flowing — see .github/dependabot.yml.
FROM php:8.4-cli-bookworm@sha256:ca4b9f44c281f6214a08313185b306368b9ec1e9a73b54b4625774a254106e1d

ARG OPENSWOOLE_VERSION=
ARG UOPZ_VERSION=
# ext-zealphp git tag to build (see setup.sh). Defaults to v0.3.37 there.
ARG ZEALPHP_EXT_VERSION=
# Optional: rebuild opcache with the function-dups-fix patch so opcache can stay
# fully ON in coroutine-legacy mode for legacy require_once apps (e.g. WordPress)
# without "Cannot redeclare function". OFF by default — stock opcache otherwise.
# Build with --build-arg ZEALPHP_PATCH_OPCACHE=1 to enable. See
# patches/opcache-function-dups-fix.patch and php-src issue #22214.
ARG ZEALPHP_PATCH_OPCACHE=

ENV COMPOSER_ALLOW_SUPERUSER=1 \
    ZEALPHP_HOST=0.0.0.0 \
    ZEALPHP_PORT=8080

COPY setup.sh /tmp/zealphp-setup.sh
RUN bash /tmp/zealphp-setup.sh --docker && rm /tmp/zealphp-setup.sh

# Patched-opcache build step (gated by ZEALPHP_PATCH_OPCACHE). When enabled it
# rebuilds opcache.so from the image's own PHP source with the dups-fix patch and
# turns on opcache.dups_fix so the per-request re-execution model never trips
# "Cannot redeclare class/function". No-op (and no extra layers cost beyond the
# COPY) when the arg is unset.
COPY patches/ /tmp/zealphp-patches/
COPY docker/patch-opcache.sh /tmp/zealphp-patch-opcache.sh
RUN if [ -n "$ZEALPHP_PATCH_OPCACHE" ]; then \
      bash /tmp/zealphp-patch-opcache.sh /tmp/zealphp-patches/opcache-function-dups-fix.patch && \
      echo 'opcache.dups_fix=1' > "$PHP_INI_DIR/conf.d/zz-zealphp-opcache-dupsfix.ini"; \
    fi; \
    rm -rf /tmp/zealphp-patches /tmp/zealphp-patch-opcache.sh

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader

COPY . .

EXPOSE 8080

CMD ["php", "app.php"]

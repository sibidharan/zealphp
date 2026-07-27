# Pinned by digest for supply-chain reproducibility (OpenSSF Scorecard
# Pinned-Dependencies). Dependabot's docker ecosystem bumps this digest weekly
# so security patches keep flowing — see .github/dependabot.yml.
FROM php:8.5-cli-bookworm@sha256:1ff2cdf2754bcac61128bfda455d418639c0f45a8b1583b4b90f9873a2ba1368

ARG OPENSWOOLE_VERSION=
ARG UOPZ_VERSION=
# ext-zealphp git tag to build (see setup.sh). Defaults to v0.3.41 there.
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
# writes the full coroutine-legacy opcache recipe. This is the structural fix for
# the unmodified-WordPress per-request memory leak (root-caused 2026-06-13,
# docs/architecture/2026-06-13-use-zend-alloc-reframe.md): Stage 7 re-EXECUTES
# require_once'd files per request (side effects stay fresh) but with opcache ON it
# runs the CACHED op_array instead of RE-COMPILING, so no orphaned inherited-loser
# CE is minted (~6.4 MB/req leak → OOM → worker-exit corruption — all gone). The
# ini must set:
#   - opcache.enable_cli=1        — CRITICAL: ZealPHP runs as the CLI SAPI, where
#                                   opcache is OFF by default. Without this the
#                                   patch + dups_fix do NOTHING and the leak stays.
#   - opcache.dups_fix=1          — first-wins on re-copied CLASS declarations
#                                   (the patched opcache extends this to FUNCTIONS).
#   - opcache.validate_timestamps=0 — production: don't re-check mtimes (=> no
#                                   recompile). Drop to =1 in dev (edits visible).
# Validated: unmodified WP coroutine-legacy → sequential RSS-flat, wrk -c60 0
# crashes / 0 OOM / 0 redeclare, 10x throughput. No-op when the arg is unset.
COPY patches/ /tmp/zealphp-patches/
COPY docker/patch-opcache.sh /tmp/zealphp-patch-opcache.sh
RUN if [ -n "$ZEALPHP_PATCH_OPCACHE" ]; then \
      bash /tmp/zealphp-patch-opcache.sh /tmp/zealphp-patches/opcache-function-dups-fix.patch && \
      { echo 'opcache.enable=1'; \
        echo 'opcache.enable_cli=1'; \
        echo 'opcache.dups_fix=1'; \
        echo 'opcache.validate_timestamps=0'; \
        echo 'opcache.memory_consumption=256'; \
      } > "$PHP_INI_DIR/conf.d/zz-zealphp-opcache-legacy.ini"; \
    fi; \
    rm -rf /tmp/zealphp-patches /tmp/zealphp-patch-opcache.sh

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install --no-dev --prefer-dist --no-interaction --no-progress --optimize-autoloader

COPY . .

EXPOSE 8080

CMD ["php", "app.php"]

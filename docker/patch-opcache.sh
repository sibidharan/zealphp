#!/usr/bin/env bash
# Rebuild the official php image's opcache.so with the function-dups-fix patch
# (patches/opcache-function-dups-fix.patch). Lets opcache stay fully ON in
# coroutine-legacy mode for legacy require_once apps (e.g. WordPress) without
# "Cannot redeclare function" — see php-src issue #22214. Uses the official
# image's docker-php-source tooling. Invoked from the Dockerfile only when
# ZEALPHP_PATCH_OPCACHE is set; otherwise the image keeps stock opcache.
set -euo pipefail
PATCH="${1:?usage: patch-opcache.sh <patch-file>}"

echo "[patch-opcache] ensuring 'patch' is available"
if ! command -v patch >/dev/null 2>&1; then
  apt-get update -qq && apt-get install -y --no-install-recommends patch
fi

echo "[patch-opcache] extracting matching PHP source"
docker-php-source extract                       # -> /usr/src/php (exact image version)

echo "[patch-opcache] applying $PATCH"
patch -p1 -d /usr/src/php < "$PATCH"

echo "[patch-opcache] building opcache.so"
cd /usr/src/php/ext/opcache
phpize >/dev/null
./configure >/dev/null
make -j"$(nproc)" >/dev/null

EXTDIR="$(php-config --extension-dir)"
cp ./.libs/opcache.so "$EXTDIR/opcache.so"
echo "[patch-opcache] installed patched opcache.so -> $EXTDIR/opcache.so"

cd /
docker-php-source delete
echo "[patch-opcache] done"

#!/usr/bin/env bash
#
# Build the phpDocumentor API reference into public/docs/api/.
#
# Launched DETACHED by app.php on first boot (so it never blocks server
# startup), or run manually any time. Safe to run repeatedly — exits
# early if the reference is already built.
#
#   ./scripts/build-api-docs.sh           # build if missing
#   rm -rf public/docs/api && ./scripts/build-api-docs.sh   # force rebuild
#
# Requires: curl (to fetch the PHAR on first run) + php on PATH.
#
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
INDEX="$ROOT/public/docs/api/index.html"
PHAR="$ROOT/tools/phpdoc.phar"
PHAR_URL="https://github.com/phpDocumentor/phpDocumentor/releases/latest/download/phpDocumentor.phar"

# Already built — nothing to do.
if [ -f "$INDEX" ]; then
  exit 0
fi

mkdir -p "$ROOT/tools"

# Fetch the self-contained PHAR (33 MB) once. The PHAR bundles its own
# deps, sidestepping the league/uri ↔ symfony/console conflict that
# breaks the Composer-installed phpdocumentor.
if [ ! -f "$PHAR" ]; then
  echo "[build-api-docs] downloading phpDocumentor PHAR (33 MB, one-time)..."
  if ! curl -fsSL "$PHAR_URL" -o "$PHAR"; then
    echo "[build-api-docs] PHAR download failed — /docs/api/ will show the fallback page." >&2
    exit 1
  fi
fi

# ABSOLUTE paths are required: phpDocumentor 3.10's default DSN
# resolution ("file://./") trips a league/uri 7.x parser bug
# ("Impossible to create the root directory"). Passing fully-qualified
# -d/-t paths bypasses it.
echo "[build-api-docs] generating API reference into public/docs/api/ ..."
php "$PHAR" \
  -d "$ROOT/src" \
  -t "$ROOT/public/docs/api" \
  --title="ZealPHP API Reference" \
  --defaultpackagename="ZealPHP" \
  --no-interaction

echo "[build-api-docs] done."

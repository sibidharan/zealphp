#!/usr/bin/env bash
# Local coverage runner using a locally-built pcov (no system install).
# Usage: scripts/coverage.sh [phpunit args...]   (defaults to tests/Unit/)
set -euo pipefail
PCOV="${PCOV_SO:-/tmp/pcov-build/pcov-1.0.12/modules/pcov.so}"
[ -f "$PCOV" ] || { echo "pcov.so not found at $PCOV — build it or set PCOV_SO"; exit 1; }
ARGS="${*:-tests/Unit/}"
php -d extension="$PCOV" -d pcov.enabled=1 -d pcov.directory=src \
    vendor/bin/phpunit $ARGS --coverage-clover /tmp/cov.xml --coverage-text 2>/dev/null \
    | grep -E 'Lines:|Methods:' || true

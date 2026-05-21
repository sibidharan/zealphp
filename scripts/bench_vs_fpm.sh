#!/usr/bin/env bash
set -euo pipefail
#
# ZealPHP vs Apache + PHP-FPM — Per-Request Cost Bench
#
# Measures req/s and per-request latency on the SAME JSON workload for:
#   1. ZealPHP coroutine mode      (default: http://127.0.0.1:8080/json)
#   2. Apache + PHP-FPM             (set FPM_URL to enable)
#   3. ZealPHP legacy CGI bridge    (set LEGACY_CGI_URL to enable)
#
# Apache/FPM setup is out of scope for this script — it needs root and
# distro-specific config. Point it at whatever you already have running.
#
# Usage:
#   scripts/bench_vs_fpm.sh
#   FPM_URL=http://127.0.0.1:8081/json.php scripts/bench_vs_fpm.sh
#   FPM_URL=http://127.0.0.1:8081/json.php \
#     LEGACY_CGI_URL=http://127.0.0.1:8082/json scripts/bench_vs_fpm.sh
#
# Knobs:
#   CONCURRENCY    default 200
#   REQUESTS       default 50000
#   ZEAL_URL       default http://127.0.0.1:8080/json
#   MIXED_URL      unset → skipped (Mixed-mode: superglobals(true) +
#                  processIsolation(false) + enableCoroutine(false) — the
#                  apples-to-apples PHP-FPM-equivalent execution model)
#   FPM_URL        unset → skipped
#   FORK_CGI_URL   unset → skipped (App::cgiMode('fork') instance)
#   LEGACY_CGI_URL unset → skipped (App::cgiMode('proc') instance)
#

CONCURRENCY="${CONCURRENCY:-200}"
REQUESTS="${REQUESTS:-50000}"
ZEAL_URL="${ZEAL_URL:-http://127.0.0.1:8080/json}"
MIXED_URL="${MIXED_URL:-}"
FPM_URL="${FPM_URL:-}"
FORK_CGI_URL="${FORK_CGI_URL:-}"
LEGACY_CGI_URL="${LEGACY_CGI_URL:-}"

die() { echo "ERROR: $*" >&2; exit 1; }
have() { command -v "$1" >/dev/null 2>&1; }

have ab || die "Apache Bench (ab) not found. Install: apt install apache2-utils"
have curl || die "curl not found"

probe() {
    local url="$1"
    curl -sS -o /dev/null -w '%{http_code}' --max-time 2 "$url" 2>/dev/null || echo "000"
}

run_ab() {
    local label="$1" url="$2"
    echo "── $label"
    echo "   $url"
    local out
    out=$(ab -n "$REQUESTS" -c "$CONCURRENCY" -k -l "$url" 2>&1 || true)
    local rps tpr fail
    rps=$(echo "$out" | awk -F: '/Requests per second/ {gsub(/[^0-9.]/,"",$2); print $2; exit}')
    tpr=$(echo "$out" | awk -F: '/Time per request.*mean\)/ {gsub(/[^0-9.]/,"",$2); print $2; exit}' | head -1)
    fail=$(echo "$out" | awk -F: '/Failed requests/ {gsub(/[^0-9]/,"",$2); print $2; exit}')
    printf "   req/s = %s   |   ms/req = %s   |   failed = %s\n\n" \
        "${rps:-?}" "${tpr:-?}" "${fail:-0}"
}

echo "==========================================================="
echo "  ZealPHP vs PHP-FPM — Per-Request Cost"
echo "  c=$CONCURRENCY  n=$REQUESTS  keep-alive on"
echo "==========================================================="
echo ""

# --- 1. ZealPHP coroutine mode -------------------------------------------
if [ "$(probe "$ZEAL_URL")" = "200" ]; then
    run_ab "ZealPHP coroutine mode" "$ZEAL_URL"
else
    echo "── ZealPHP coroutine mode"
    echo "   SKIPPED — $ZEAL_URL is not responding 200. Start the server with:"
    echo "      php app.php"
    echo ""
fi

# --- 2. ZealPHP Mixed-mode (FPM-equivalent) ------------------------------
if [ -n "$MIXED_URL" ]; then
    if [ "$(probe "$MIXED_URL")" = "200" ]; then
        run_ab "ZealPHP Mixed-mode — processIsolation(false) + enableCoroutine(false)" "$MIXED_URL"
    else
        echo "── ZealPHP Mixed-mode (FPM-equivalent)"
        echo "   SKIPPED — $MIXED_URL is not responding 200."
        echo ""
    fi
else
    echo "── ZealPHP Mixed-mode (FPM-equivalent)"
    echo "   SKIPPED — set MIXED_URL to enable. Start a ZealPHP instance with"
    echo "   App::superglobals(true) + App::processIsolation(false) + App::enableCoroutine(false)."
    echo "   This is the apples-to-apples PHP-FPM execution model (in-process, no fork)."
    echo ""
fi

# --- 3. Apache + PHP-FPM -------------------------------------------------
if [ -n "$FPM_URL" ]; then
    if [ "$(probe "$FPM_URL")" = "200" ]; then
        run_ab "Apache + PHP-FPM" "$FPM_URL"
    else
        echo "── Apache + PHP-FPM"
        echo "   SKIPPED — $FPM_URL is not responding 200."
        echo ""
    fi
else
    echo "── Apache + PHP-FPM"
    echo "   SKIPPED — set FPM_URL to enable. Example setup:"
    echo "      apt install apache2 php8.3-fpm libapache2-mod-fcgid"
    echo "      a2enmod proxy proxy_fcgi"
    echo "      # point a vhost at php-fpm on a free port, drop json.php"
    echo "      # with 'header(\"Content-Type:application/json\"); echo \"{}\";'"
    echo ""
fi

# --- 4. ZealPHP fork CGI bridge (App::cgiMode('fork')) -------------------
if [ -n "$FORK_CGI_URL" ]; then
    if [ "$(probe "$FORK_CGI_URL")" = "200" ]; then
        run_ab "ZealPHP fork CGI bridge — cgiMode('fork')" "$FORK_CGI_URL"
    else
        echo "── ZealPHP fork CGI bridge — cgiMode('fork')"
        echo "   SKIPPED — $FORK_CGI_URL is not responding 200."
        echo ""
    fi
else
    echo "── ZealPHP fork CGI bridge — cgiMode('fork')"
    echo "   SKIPPED — set FORK_CGI_URL to enable. Start a ZealPHP instance with"
    echo "   App::superglobals(true) + App::processIsolation(true) + App::cgiMode('fork')."
    echo ""
fi

# --- 5. ZealPHP legacy CGI bridge (App::cgiMode('proc'), default) --------
if [ -n "$LEGACY_CGI_URL" ]; then
    if [ "$(probe "$LEGACY_CGI_URL")" = "200" ]; then
        run_ab "ZealPHP legacy CGI bridge — cgiMode('proc')" "$LEGACY_CGI_URL"
    else
        echo "── ZealPHP legacy CGI bridge — cgiMode('proc')"
        echo "   SKIPPED — $LEGACY_CGI_URL is not responding 200."
        echo ""
    fi
else
    echo "── ZealPHP legacy CGI bridge — cgiMode('proc')"
    echo "   SKIPPED — set LEGACY_CGI_URL to enable. Start a second ZealPHP"
    echo "   instance with App::superglobals(true) on a different port."
    echo ""
fi

echo "==========================================================="
echo "Expected shape (NOT a promise — depends on hardware):"
echo "  coroutine     ≫ FPM (no FCGI hop, in-process routing)"
echo "  FPM           > legacy CGI bridge (FPM keeps workers warm)"
echo "  legacy CGI    ≈ ~30–50 ms per request (proc_open fresh PHP)"
echo ""
echo "If your numbers don't match, check: opcache enabled? FPM"
echo "pm.max_children? workers vs. cores? keep-alive on the load gen?"
echo "==========================================================="

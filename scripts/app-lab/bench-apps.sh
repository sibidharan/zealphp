#!/usr/bin/env bash
# Benchmark each app's hot path against the Apache+mod_php reference.
# Captures RPS, p50/p99 latency, and worker memory footprint.
#
# Default workload: 1000 requests, concurrency 50, 60s warmup.
# Override via env: N=5000 C=100 ./bench-apps.sh wordpress

set -euo pipefail

N=${N:-1000}
C=${C:-50}
APP=${1:-all}
OUT=${OUT:-./results/$(date +%Y%m%d-%H%M%S)}
mkdir -p "$OUT"

declare -A HOT=(
    [wordpress]="/wp-login.php"
    [joomla]="/"
    [phpmyadmin]="/"
    [adminer]="/"
    [privatebin]="/"
    [traditional]="/"
    [grav]="/"
    [lychee]="/"
)

bench_one() {
    local app="$1" path="$2" zeal_url="$3" apache_url="$4"
    echo
    echo "=== $app ($path) ==="

    # Warmup — opcache + worker pool warm up
    curl -s -o /dev/null --max-time 5 "${zeal_url}${path}" >/dev/null || true
    curl -s -o /dev/null --max-time 5 "${apache_url}${path}" >/dev/null || true

    echo "--- ZealPHP ---"
    ab -q -n "$N" -c "$C" -e "$OUT/${app}-zealphp.csv" "${zeal_url}${path}" 2>&1 \
        | tee "$OUT/${app}-zealphp.txt" | grep -E "Requests per second|Time per request|Percentage of the requests" | head -3

    echo "--- Apache+mod_php ---"
    ab -q -n "$N" -c "$C" -e "$OUT/${app}-apache.csv" "${apache_url}${path}" 2>&1 \
        | tee "$OUT/${app}-apache.txt" | grep -E "Requests per second|Time per request|Percentage of the requests" | head -3
}

if [[ "$APP" == "all" ]]; then
    bench_one wordpress "/wp-login.php" "http://localhost:8093" "http://localhost:8094"
else
    if [[ -z "${HOT[$APP]:-}" ]]; then
        echo "Unknown app: $APP. Known: ${!HOT[*]}"
        exit 1
    fi
    bench_one "$APP" "${HOT[$APP]}" "http://localhost:8093" "http://localhost:8094"
fi

echo
echo "==> Results in $OUT/"
echo "==> Side-by-side comparison:"
for f in "$OUT"/*-zealphp.txt; do
    app=$(basename "$f" -zealphp.txt)
    z=$(grep "Requests per second" "$f" | awk '{print $4}')
    a=$(grep "Requests per second" "$OUT/${app}-apache.txt" 2>/dev/null | awk '{print $4}')
    printf "%-15s ZealPHP=%s rps   Apache=%s rps\n" "$app" "$z" "$a"
done

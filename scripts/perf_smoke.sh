#!/usr/bin/env bash
#
# Perf-regression smoke — a *catastrophe detector*, not a micro-benchmark.
#
# CI runners are shared and noisy, so a tight req/s gate would flap. This asserts
# two things only: (1) zero failed requests under a burst, and (2) throughput
# stays above a deliberately conservative floor (default 1500 req/s, vs ~10k on a
# dev box) — enough to catch a 5–7x regression (a broken hot path, an accidental
# blocking call, a per-request leak) without false positives. The real perf
# numbers come from the offline Docker harness (bench/, PERF.md).
#
# Env: URL, REQUESTS, CONCURRENCY, PERF_MIN_RPS.
set -euo pipefail

URL="${URL:-http://127.0.0.1:8080/json}"
REQUESTS="${REQUESTS:-5000}"
CONCURRENCY="${CONCURRENCY:-50}"
PERF_MIN_RPS="${PERF_MIN_RPS:-1500}"

echo "perf-smoke: $REQUESTS requests, c=$CONCURRENCY, floor=${PERF_MIN_RPS} req/s -> $URL"

out="$(ab -n "$REQUESTS" -c "$CONCURRENCY" -q "$URL" 2>&1)"

failed="$(echo "$out" | awk '/Failed requests:/ {print $3}')"
non2xx="$(echo "$out" | awk '/Non-2xx responses:/ {print $3}')"
rps="$(echo "$out" | awk '/Requests per second:/ {print $4}')"

echo "  failed=$failed non2xx=${non2xx:-0} rps=$rps"

if [ "${failed:-1}" != "0" ]; then
    echo "::error::perf-smoke FAILED — $failed failed requests under load"
    exit 1
fi

# Integer-floor comparison (strip the decimal from ab's req/s).
rps_int="${rps%%.*}"
if [ -z "$rps_int" ] || [ "$rps_int" -lt "$PERF_MIN_RPS" ]; then
    echo "::error::perf-smoke FAILED — throughput ${rps} req/s is below floor ${PERF_MIN_RPS} req/s (catastrophic regression?)"
    exit 1
fi

echo "perf-smoke OK — ${rps} req/s, 0 failures (floor ${PERF_MIN_RPS})"

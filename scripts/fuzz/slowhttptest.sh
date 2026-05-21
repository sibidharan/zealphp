#!/usr/bin/env bash
#
# slowhttptest.sh — slow-HTTP (slowloris / R-U-Dead-Yet) survival probe.
#
# WHAT THIS PROVES
# ----------------
# slowhttptest (https://github.com/shekyan/slowhttptest) opens many connections
# and dribbles either the request HEADERS (slowloris) or the request BODY
# (R-U-Dead-Yet) a few bytes at a time, then measures how long the server keeps
# those connections alive and whether it stays available to a normal client.
#
# This validates a KNOWN, DOCUMENTED ZealPHP gap: OpenSwoole's HTTP server has
# no per-request header/body read timeout (no Apache `mod_reqtimeout`
# equivalent wired in), so a slow client can hold a worker connection open
# indefinitely. Empirically (2026-05-21, modest -c 50 scale): all 50 slow
# connections stayed `connected` for the full 30s test, `closed=0` — the server
# never terminated the drip. At 50 connections the worker pool isn't exhausted
# so "service available" stays YES, but the drip-survival property is the
# finding: there is no read-timeout to lean on. A real mitigation is a front
# proxy (Traefik/nginx) with request timeouts, or an OpenSwoole read-timeout.
#
# This harness is the "proven, not claimed" evidence for that gap. It is NOT a
# pass/fail gate (the gap is expected); it captures the survival stats so a
# future read-timeout fix can be measured against this baseline.
#
# USAGE
# -----
#   scripts/fuzz/slowhttptest.sh [headers|body|both] [HOST] [PORT]
#   default mode: both
#
# ENV
#   SLOWHTTPTEST  path to binary (default: PATH, then /tmp/slowhttptest/src/slowhttptest)
#   CONNS         connections           (default 50)
#   INTERVAL      seconds between drip   (default 10)
#   RATE          connections per second (default 20)
#   DURATION      test length in seconds (default 30)
#   OUTDIR        report dir             (default ./bench/slowhttp)
#
set -euo pipefail

MODE="${1:-both}"
HOST="${2:-127.0.0.1}"
PORT="${3:-8080}"

CONNS="${CONNS:-50}"
INTERVAL="${INTERVAL:-10}"
RATE="${RATE:-20}"
DURATION="${DURATION:-30}"
OUTDIR="${OUTDIR:-./bench/slowhttp}"

BIN="${SLOWHTTPTEST:-}"
if [ -z "$BIN" ]; then
  if command -v slowhttptest >/dev/null 2>&1; then
    BIN="$(command -v slowhttptest)"
  elif [ -x /tmp/slowhttptest/src/slowhttptest ]; then
    BIN="/tmp/slowhttptest/src/slowhttptest"
  else
    echo "ERROR: slowhttptest not found. Install: apt-get install slowhttptest" >&2
    echo "  or build: git clone https://github.com/shekyan/slowhttptest && cd slowhttptest && ./configure && make" >&2
    exit 2
  fi
fi

mkdir -p "$OUTDIR"

run_headers() {
  echo "=== SLOW HEADERS (slowloris) -> http://$HOST:$PORT/ ==="
  # -H slow-headers, -t verb, -x max follow-up bytes, -p probe timeout,
  # -g + -o emit CSV/HTML reports for the survival timeline.
  "$BIN" -c "$CONNS" -H -i "$INTERVAL" -r "$RATE" -t GET \
    -u "http://$HOST:$PORT/" -x 24 -p 3 -l "$DURATION" \
    -g -o "$OUTDIR/slow_headers" || true
  echo "  report: $OUTDIR/slow_headers.csv (cols: sec,closed,pending,connected,service_avail%)"
}

run_body() {
  echo "=== SLOW BODY (R-U-Dead-Yet) -> http://$HOST:$PORT/json ==="
  "$BIN" -c "$CONNS" -B -i "$INTERVAL" -r "$RATE" -t POST \
    -u "http://$HOST:$PORT/json" -x 24 -p 3 -l "$DURATION" -s 8192 \
    -g -o "$OUTDIR/slow_body" || true
  echo "  report: $OUTDIR/slow_body.csv"
}

case "$MODE" in
  headers) run_headers ;;
  body)    run_body ;;
  both)    run_headers; echo; run_body ;;
  *) echo "unknown mode '$MODE' (use: headers|body|both)" >&2; exit 2 ;;
esac

echo
echo "NOTE: closed=0 / connected=$CONNS for the full $DURATION s means ZealPHP"
echo "      held the slow connections open (no read-timeout). Expected gap;"
echo "      mitigate with a front proxy request-timeout. See docs/fuzzing.md."

#!/usr/bin/env bash
#
# radamsa_run.sh — wire-mutation fuzz of ZealPHP's HTTP/1.1 framing.
#
# WHAT THIS PROVES
# ----------------
# Radamsa (https://gitlab.com/akihe/radamsa) takes a known-valid HTTP request
# from the seed corpus (scripts/fuzz/corpus/*.raw — derived from the framing
# fixtures in tests/Integration/Http1FramingConformanceTest.php), mutates the
# raw bytes, and we replay the mutant over a raw TCP socket. For every mutant
# the server MUST reach a definite outcome inside a hard timeout:
#
#     - a clean HTTP status (2xx valid / 4xx rejected / 3xx / 5xx-no-trace), or
#     - a connection close.
#
# Two outcomes are FAILURES the harness fails the run on:
#     - HANG       : connect/read timed out -> a worker is stuck (slow-client /
#                    framing DoS surface).
#     - TRACE_5xx  : a 5xx whose body leaks a PHP stack trace (uncaught
#                    exception reached the client -> info disclosure).
#
# This is the "proven, not claimed" complement to the deterministic framing
# unit tests: those pin specific crafted cases; this throws hundreds of random
# mutations at the same surface and asserts the safety invariant holds.
#
# USAGE
# -----
#   scripts/fuzz/radamsa_run.sh [ITERATIONS] [HOST] [PORT]
#   ITERATIONS  default 500   (CI uses a smaller bounded count)
#   HOST        default 127.0.0.1
#   PORT        default 8080
#
# ENV
#   RADAMSA   path to radamsa binary (default: looks on PATH, then /tmp/radamsa/bin/radamsa)
#   PYTHON    python3 interpreter    (default: python3 on PATH)
#   TIMEOUT   per-request socket timeout in seconds (default: 4)
#   SEED      radamsa --seed for reproducibility (default: random)
#
set -euo pipefail

ITERATIONS="${1:-500}"
HOST="${2:-127.0.0.1}"
PORT="${3:-8080}"
TIMEOUT="${TIMEOUT:-4}"

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
CORPUS_DIR="$HERE/corpus"
SENDER="$HERE/send_raw.py"

# Resolve radamsa: PATH first, then the conventional source-build location.
RADAMSA="${RADAMSA:-}"
if [ -z "$RADAMSA" ]; then
  if command -v radamsa >/dev/null 2>&1; then
    RADAMSA="$(command -v radamsa)"
  elif [ -x /tmp/radamsa/bin/radamsa ]; then
    RADAMSA="/tmp/radamsa/bin/radamsa"
  else
    echo "ERROR: radamsa not found. Build it: git clone https://gitlab.com/akihe/radamsa /tmp/radamsa && make -C /tmp/radamsa" >&2
    exit 2
  fi
fi
PYTHON="${PYTHON:-python3}"

if [ ! -d "$CORPUS_DIR" ] || [ -z "$(ls "$CORPUS_DIR"/*.raw 2>/dev/null)" ]; then
  echo "ERROR: no seed corpus at $CORPUS_DIR/*.raw" >&2
  exit 2
fi

mapfile -t SEEDS < <(ls "$CORPUS_DIR"/*.raw)
NSEEDS=${#SEEDS[@]}

SEED_ARG=()
if [ -n "${SEED:-}" ]; then SEED_ARG=(--seed "$SEED"); fi

echo "radamsa fuzz: $ITERATIONS iterations against $HOST:$PORT (timeout ${TIMEOUT}s, $NSEEDS seeds)"
echo "radamsa: $RADAMSA   sender: $SENDER"
echo

ok2xx=0; ok4xx=0; okclose=0; ok5xx=0; hang=0; trace5xx=0; connfail=0
FAIL_LOG="$(mktemp)"
MUTANT="$(mktemp)"
trap 'rm -f "$FAIL_LOG" "$MUTANT"' EXIT

for ((i = 1; i <= ITERATIONS; i++)); do
  seed="${SEEDS[$((RANDOM % NSEEDS))]}"
  # Mutate the seed into a temp file (NOT $(...) — command substitution strips
  # NUL bytes, which radamsa emits and which exercise the parser's binary path)
  # then stream the raw file into the socket sender.
  set +e
  "$RADAMSA" "${SEED_ARG[@]}" "$seed" > "$MUTANT"
  "$PYTHON" "$SENDER" "$HOST" "$PORT" "$TIMEOUT" < "$MUTANT"
  rc=$?
  set -e
  case "$rc" in
    0) ok2xx=$((ok2xx + 1)) ;;
    1) ok4xx=$((ok4xx + 1)) ;;
    2) okclose=$((okclose + 1)) ;;
    3) ok5xx=$((ok5xx + 1)) ;;
    10) hang=$((hang + 1)); { echo "=== HANG iter=$i seed=$(basename "$seed") ==="; head -c 400 "$MUTANT" | xxd; } >> "$FAIL_LOG" ;;
    11) trace5xx=$((trace5xx + 1)); { echo "=== TRACE_5xx iter=$i seed=$(basename "$seed") ==="; head -c 400 "$MUTANT" | xxd; } >> "$FAIL_LOG" ;;
    12) connfail=$((connfail + 1)); { echo "=== CONNECT_FAIL iter=$i ==="; } >> "$FAIL_LOG" ;;
  esac
  if [ $((i % 100)) -eq 0 ]; then
    echo "  ...$i/$ITERATIONS  2xx=$ok2xx 4xx=$ok4xx close=$okclose 5xx_clean=$ok5xx | HANG=$hang TRACE=$trace5xx CONNFAIL=$connfail"
  fi
done

echo
echo "================ radamsa fuzz tally ================"
echo "  iterations      : $ITERATIONS"
echo "  OK  2xx accepted : $ok2xx"
echo "  OK  4xx rejected : $ok4xx"
echo "  OK  conn close   : $okclose"
echo "  OK  5xx no-trace : $ok5xx"
echo "  ----------------------------------------"
echo "  FAIL hang/timeout: $hang"
echo "  FAIL 5xx + trace : $trace5xx"
echo "  FAIL connect     : $connfail"
echo "==================================================="

fail_total=$((hang + trace5xx + connfail))
if [ "$fail_total" -gt 0 ]; then
  echo
  echo "FAILURES detected ($fail_total). First offending mutants:" >&2
  head -c 4000 "$FAIL_LOG" >&2
  exit 1
fi
echo "PASS: no hangs, no stack-trace leaks across $ITERATIONS mutations."

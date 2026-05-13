#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

PHP_BIN="${PHP_BIN:-php}"
NODE_BIN="${NODE_BIN:-node}"
WORKERS="${WORKERS:-4}"
THREADS="${THREADS:-4}"
DURATION="${DURATION:-15s}"
WARMUP="${WARMUP:-5s}"
CONCURRENCY_LIST="${CONCURRENCY_LIST:-1,10,50,100,200,500,1000}"
PATHS="${PATHS:-/raw/bench,/json}"
ZEAL_PORT="${ZEAL_PORT:-18080}"
NODE_PORT="${NODE_PORT:-18081}"
BIND_HOST="${BIND_HOST:-0.0.0.0}"
OUTPUT_DIR="${OUTPUT_DIR:-$ROOT_DIR/bench/results/compare}"
ZEAL_PID=""
NODE_PID=""
STAMP=""

usage() {
    cat <<'USAGE'
Quad-core ZealPHP vs Node.js benchmark comparison

Starts both servers with the same worker count, benchmarks them sequentially
with wrk, and writes raw logs plus CSV summaries.

Usage:
  scripts/bench_compare.sh [options]

Examples:
  scripts/bench_compare.sh
  scripts/bench_compare.sh --workers 4 --threads 4 --p1000 --duration 30s
  scripts/bench_compare.sh --paths /raw/bench,/json,/co --concurrency 10,100,500

Options:
  --workers N          HTTP workers for both ZealPHP and Node.js
  --threads N          wrk threads
  --duration D         wrk duration per run, for example 15s or 1m
  --warmup D           wrk warmup per path/runtime; use 0 to disable
  --concurrency LIST   Comma-separated concurrency values
  --p1000              Shorthand for 1,10,50,100,200,500,1000
  --paths LIST         Comma-separated paths to compare
  --zeal-port N        ZealPHP port
  --node-port N        Node.js port
  --output-dir DIR     Output directory
  -h, --help           Show this help

Requires: php, node, wrk, curl
USAGE
}

die() {
    echo "bench_compare.sh: $*" >&2
    exit 1
}

have() {
    command -v "$1" >/dev/null 2>&1
}

trim() {
    local value="$1"
    value="${value#"${value%%[![:space:]]*}"}"
    value="${value%"${value##*[![:space:]]}"}"
    printf '%s' "$value"
}

csv_field() {
    local value="$1"
    value="${value//\"/\"\"}"
    printf '"%s"' "$value"
}

profile_until() {
    local max="$1"
    local levels=(1 10 50 100 200 500 1000 1500 2000 5000)
    local out=()
    local level
    for level in "${levels[@]}"; do
        if (( level <= max )); then
            out+=("$level")
        fi
    done
    if [[ "${out[*]}" != *" $max"* && "${out[*]}" != "$max"* ]]; then
        out+=("$max")
    fi
    local joined=""
    for level in "${out[@]}"; do
        if [[ -n "$joined" ]]; then
            joined+=","
        fi
        joined+="$level"
    done
    printf '%s\n' "$joined"
}

url_for() {
    local runtime="$1"
    local path="$2"
    local port="$ZEAL_PORT"
    if [[ "$runtime" == "node" ]]; then
        port="$NODE_PORT"
    fi
    if [[ "$path" != /* ]]; then
        path="/$path"
    fi
    printf 'http://127.0.0.1:%s%s' "$port" "$path"
}

ready() {
    local runtime="$1"
    curl -fsS --max-time 2 "$(url_for "$runtime" "/raw/bench")" >/dev/null 2>&1
}

wait_ready() {
    local runtime="$1"
    local attempt
    for attempt in $(seq 1 60); do
        if ready "$runtime"; then
            return 0
        fi
        sleep 0.5
    done
    return 1
}

kill_tree() {
    local pid="$1"
    local child
    for child in $(pgrep -P "$pid" 2>/dev/null || true); do
        kill_tree "$child"
    done
    kill "$pid" >/dev/null 2>&1 || true
}

cleanup() {
    if [[ -n "$ZEAL_PID" ]]; then
        kill_tree "$ZEAL_PID"
        wait "$ZEAL_PID" >/dev/null 2>&1 || true
    fi
    if [[ -n "$NODE_PID" ]]; then
        kill_tree "$NODE_PID"
        wait "$NODE_PID" >/dev/null 2>&1 || true
    fi
}

set_fd_limit() {
    local target=65535
    local current
    current="$(ulimit -n || true)"
    if [[ -n "$current" && "$current" != "unlimited" && "$current" -lt "$target" ]]; then
        ulimit -n "$target" 2>/dev/null || true
    fi
}

start_zealphp() {
    local log="$OUTPUT_DIR/server/zealphp-$STAMP.log"
    echo "Starting ZealPHP on :$ZEAL_PORT with $WORKERS workers"
    (
        cd "$ROOT_DIR"
        env \
            ZEALPHP_HOST="$BIND_HOST" \
            ZEALPHP_PORT="$ZEAL_PORT" \
            ZEALPHP_WORKERS="$WORKERS" \
            ZEALPHP_TASK_WORKERS=0 \
            ZEALPHP_BENCH_MODE="${ZEALPHP_BENCH_MODE:-1}" \
            ZEALPHP_LOG_ASYNC="${ZEALPHP_LOG_ASYNC:-1}" \
            ZEALPHP_LOG_DIR="${ZEALPHP_LOG_DIR:-/tmp/zealphp}" \
            ZEALPHP_DEBUG_LOG="${ZEALPHP_DEBUG_LOG:-0}" \
            ZEALPHP_ACCESS_LOG="${ZEALPHP_ACCESS_LOG:-0}" \
            ZEALPHP_PID_FILE="$OUTPUT_DIR/server/zealphp-$ZEAL_PORT.pid" \
            "$PHP_BIN" app.php
    ) >"$log" 2>&1 &
    ZEAL_PID="$!"
    wait_ready zealphp || {
        tail -n 80 "$log" >&2 || true
        die "ZealPHP did not become ready"
    }
}

start_node() {
    local log="$OUTPUT_DIR/server/node-$STAMP.log"
    echo "Starting Node.js on :$NODE_PORT with $WORKERS workers"
    (
        cd "$ROOT_DIR"
        env NODE_WORKERS="$WORKERS" NODE_PORT="$NODE_PORT" "$NODE_BIN" node_bench.js
    ) >"$log" 2>&1 &
    NODE_PID="$!"
    wait_ready node || {
        tail -n 80 "$log" >&2 || true
        die "Node.js did not become ready"
    }
}

latency_to_ms_awk='
function to_ms(value, raw) {
    raw = value
    gsub(/^[ \t]+|[ \t]+$/, "", raw)
    gsub(/,/, "", raw)
    if (raw ~ /us$/) {
        sub(/us$/, "", raw)
        return raw / 1000
    }
    if (raw ~ /ms$/) {
        sub(/ms$/, "", raw)
        return raw + 0
    }
    if (raw ~ /s$/) {
        sub(/s$/, "", raw)
        return raw * 1000
    }
    return raw + 0
}
function to_seconds(value, raw) {
    raw = value
    gsub(/^[ \t]+|[ \t]+$/, "", raw)
    gsub(/,/, "", raw)
    if (raw ~ /ms$/) {
        sub(/ms$/, "", raw)
        return raw / 1000
    }
    if (raw ~ /s$/) {
        sub(/s$/, "", raw)
        return raw + 0
    }
    if (raw ~ /m$/) {
        sub(/m$/, "", raw)
        return raw * 60
    }
    return raw + 0
}
'

parse_wrk() {
    local file="$1"
    awk "$latency_to_ms_awk"'
        $1 == "Latency" && $2 != "Distribution" { avg = to_ms($2) }
        $1 == "50%" { p50 = to_ms($2) }
        $1 == "90%" { p90 = to_ms($2) }
        $1 == "99%" { p99 = to_ms($2) }
        /requests in/ {
            for (i = 1; i <= NF; i++) {
                if ($i == "requests") {
                    total_requests = $(i - 1) + 0
                }
                if ($i == "in") {
                    total_time = to_seconds($(i + 1))
                }
            }
        }
        /Requests\/sec:/ { rps = $2 + 0 }
        /Socket errors:/ {
            for (i = 1; i <= NF; i++) {
                if ($i ~ /^[0-9]+,?$/) {
                    gsub(",", "", $i)
                    failures += $i
                }
            }
        }
        /Non-2xx or 3xx responses:/ { failures += $5 + 0 }
        END {
            printf "%d,%.3f,%.2f,%.3f,%.3f,%.3f,%.3f,%d", total_requests + 0, total_time + 0, rps + 0, avg + 0, p50 + 0, p90 + 0, p99 + 0, failures + 0
        }
    ' "$file"
}

run_wrk() {
    local runtime="$1"
    local path="$2"
    local concurrency="$3"
    local raw_file="$4"
    local url
    local run_threads="$THREADS"

    url="$(url_for "$runtime" "$path")"
    if (( run_threads > concurrency )); then
        run_threads="$concurrency"
    fi

    wrk -t "$run_threads" -c "$concurrency" -d "$DURATION" --latency "$url" >"$raw_file" 2>&1
    parse_wrk "$raw_file"
}

warmup() {
    local runtime="$1"
    local path="$2"
    local url
    local warm_threads="$THREADS"
    local warm_connections=50

    if [[ "$WARMUP" == "0" || "$WARMUP" == "0s" ]]; then
        return
    fi
    url="$(url_for "$runtime" "$path")"
    if (( warm_threads > warm_connections )); then
        warm_threads="$warm_connections"
    fi
    wrk -t "$warm_threads" -c "$warm_connections" -d "$WARMUP" "$url" >/dev/null 2>&1 || true
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --workers)
            [[ $# -ge 2 ]] || die "--workers needs a value"
            WORKERS="$2"
            shift 2
            ;;
        --threads)
            [[ $# -ge 2 ]] || die "--threads needs a value"
            THREADS="$2"
            shift 2
            ;;
        --duration)
            [[ $# -ge 2 ]] || die "--duration needs a value"
            DURATION="$2"
            shift 2
            ;;
        --warmup)
            [[ $# -ge 2 ]] || die "--warmup needs a value"
            WARMUP="$2"
            shift 2
            ;;
        --concurrency)
            [[ $# -ge 2 ]] || die "--concurrency needs a value"
            CONCURRENCY_LIST="$2"
            shift 2
            ;;
        --p1000)
            CONCURRENCY_LIST="$(profile_until 1000)"
            shift
            ;;
        --paths)
            [[ $# -ge 2 ]] || die "--paths needs a value"
            PATHS="$2"
            shift 2
            ;;
        --zeal-port)
            [[ $# -ge 2 ]] || die "--zeal-port needs a value"
            ZEAL_PORT="$2"
            shift 2
            ;;
        --node-port)
            [[ $# -ge 2 ]] || die "--node-port needs a value"
            NODE_PORT="$2"
            shift 2
            ;;
        --output-dir)
            [[ $# -ge 2 ]] || die "--output-dir needs a value"
            OUTPUT_DIR="$2"
            shift 2
            ;;
        -h|--help)
            usage
            exit 0
            ;;
        *)
            die "unknown option: $1"
            ;;
    esac
done

have curl || die "curl is required"
have wrk || die "wrk is required"
have "$PHP_BIN" || die "php binary not found: $PHP_BIN"
have "$NODE_BIN" || die "node binary not found: $NODE_BIN"

set_fd_limit
STAMP="$(date +%Y%m%d-%H%M%S)"
mkdir -p "$OUTPUT_DIR/raw" "$OUTPUT_DIR/server"
trap cleanup EXIT INT TERM

start_zealphp
start_node

DETAIL_CSV="$OUTPUT_DIR/quad-compare-$STAMP.csv"
SUMMARY_CSV="$OUTPUT_DIR/quad-compare-summary-$STAMP.csv"

printf 'timestamp,runtime,path,workers,threads,concurrency,duration,total_requests,total_time_s,rps,avg_ms,p50_ms,p90_ms,p99_ms,failures,raw_log\n' >"$DETAIL_CSV"
printf 'path,concurrency,zealphp_requests,node_requests,zealphp_time_s,node_time_s,zealphp_rps,node_rps,zealphp_vs_node_rps,zealphp_p90_ms,node_p90_ms,zealphp_failures,node_failures\n' >"$SUMMARY_CSV"

echo "Quad-core comparison: $WORKERS workers, $THREADS wrk threads"
echo "Details: $DETAIL_CSV"
echo "Summary: $SUMMARY_CSV"
echo
printf '%-12s %-8s %8s %12s %12s %10s %10s %10s %8s\n' "path" "c" "runtime" "requests" "time_s" "req/s" "avg_ms" "p90_ms" "fail"

IFS=',' read -r -a path_array <<< "$PATHS"
IFS=',' read -r -a concurrency_array <<< "$CONCURRENCY_LIST"

for raw_path in "${path_array[@]}"; do
    path="$(trim "$raw_path")"
    [[ -n "$path" ]] || continue
    if [[ "$path" != /* ]]; then
        path="/$path"
    fi

    warmup zealphp "$path"
    warmup node "$path"

    for raw_concurrency in "${concurrency_array[@]}"; do
        concurrency="$(trim "$raw_concurrency")"
        [[ -n "$concurrency" ]] || continue

        safe_path="$(printf '%s' "$path" | sed 's#[^A-Za-z0-9._-]#_#g')"

        zeal_raw="$OUTPUT_DIR/raw/${STAMP}${safe_path}-zealphp-c${concurrency}.txt"
        node_raw="$OUTPUT_DIR/raw/${STAMP}${safe_path}-node-c${concurrency}.txt"

        zeal_parsed="$(run_wrk zealphp "$path" "$concurrency" "$zeal_raw")"
        node_parsed="$(run_wrk node "$path" "$concurrency" "$node_raw")"

        IFS=',' read -r zeal_requests zeal_time zeal_rps zeal_avg zeal_p50 zeal_p90 zeal_p99 zeal_failures <<< "$zeal_parsed"
        IFS=',' read -r node_requests node_time node_rps node_avg node_p50 node_p90 node_p99 node_failures <<< "$node_parsed"

        timestamp="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
        for runtime in zealphp node; do
            if [[ "$runtime" == "zealphp" ]]; then
                total_requests="$zeal_requests"; total_time="$zeal_time"; rps="$zeal_rps"; avg="$zeal_avg"; p50="$zeal_p50"; p90="$zeal_p90"; p99="$zeal_p99"; failures="$zeal_failures"; raw_file="$zeal_raw"
            else
                total_requests="$node_requests"; total_time="$node_time"; rps="$node_rps"; avg="$node_avg"; p50="$node_p50"; p90="$node_p90"; p99="$node_p99"; failures="$node_failures"; raw_file="$node_raw"
            fi

            printf '%-12s %-8s %8s %12s %12s %10s %10s %10s %8s\n' \
                "$path" "$concurrency" "$runtime" "$total_requests" "$total_time" "$rps" "$avg" "$p90" "$failures"

            printf '%s,%s,' "$timestamp" "$runtime" >>"$DETAIL_CSV"
            csv_field "$path" >>"$DETAIL_CSV"
            printf ',%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,' \
                "$WORKERS" "$THREADS" "$concurrency" "$DURATION" "$total_requests" "$total_time" "$rps" "$avg" "$p50" "$p90" "$p99" "$failures" >>"$DETAIL_CSV"
            csv_field "$raw_file" >>"$DETAIL_CSV"
            printf '\n' >>"$DETAIL_CSV"
        done

        ratio="$(awk -v z="$zeal_rps" -v n="$node_rps" 'BEGIN { if (n > 0) printf "%.3f", z / n; else printf "0.000" }')"
        csv_field "$path" >>"$SUMMARY_CSV"
        printf ',%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s\n' \
            "$concurrency" "$zeal_requests" "$node_requests" "$zeal_time" "$node_time" \
            "$zeal_rps" "$node_rps" "$ratio" "$zeal_p90" "$node_p90" "$zeal_failures" "$node_failures" >>"$SUMMARY_CSV"
    done
done

echo
echo "Done. Summary: $SUMMARY_CSV"

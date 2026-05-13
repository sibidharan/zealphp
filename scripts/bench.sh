#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

PHP_BIN="${PHP_BIN:-php}"
SERVER_FILE="${SERVER_FILE:-$ROOT_DIR/app.php}"
BIND_HOST="${BIND_HOST:-0.0.0.0}"
HOST="${HOST:-127.0.0.1}"
PORT="${PORT:-8080}"
BASE_URL="${BASE_URL:-}"
PATHS="${PATHS:-/raw/bench}"
CONCURRENCY_LIST="${CONCURRENCY_LIST:-1,10,50,100,200,500,1000}"
THREADS="${THREADS:-16}"
WORKERS="${WORKERS:-16}"
TASK_WORKERS="${TASK_WORKERS:-0}"
DURATION="${DURATION:-15s}"
WARMUP="${WARMUP:-5s}"
REQUESTS="${REQUESTS:-20000}"
TOOL="${TOOL:-auto}"
OUTPUT_DIR="${OUTPUT_DIR:-$ROOT_DIR/bench/results}"
MAX_CONN="${MAX_CONN:-}"
MAX_COROUTINE="${MAX_COROUTINE:-}"
BACKLOG="${BACKLOG:-}"
REACTOR_NUM="${REACTOR_NUM:-}"
START_SERVER=1
PROBE_PATH="/raw/bench"
SERVER_PID=""
SERVER_STARTED=0
SERVER_LOG=""
SERVER_PID_FILE=""

usage() {
    cat <<'USAGE'
ZealPHP local benchmark runner

Defaults are tuned for a 16-core Mac:
  - 16 ZealPHP HTTP workers
  - wrk threads up to 16
  - concurrency sweep through c=1000
  - benchmark endpoint /raw/bench

Usage:
  scripts/bench.sh [options]

Examples:
  scripts/bench.sh --p1000
  scripts/bench.sh --workers 16 --threads 16 --max-concurrency 1000 --duration 30s
  scripts/bench.sh --paths /raw/bench,/json --concurrency 10,100,500,1000
  scripts/bench.sh --no-start --base-url http://127.0.0.1:8080 --path /json

Options:
  --p1000                  Shorthand for --max-concurrency 1000
  --max-concurrency N      Build a standard sweep up to N
  --concurrency LIST       Comma-separated concurrency values
  --path PATH              Single path to test
  --paths LIST             Comma-separated paths to test
  --duration DURATION      wrk duration per run, for example 15s or 1m
  --warmup DURATION        wrk warmup duration per path; use 0 to disable
  --requests N             ab total requests per run
  --workers N              ZEALPHP_WORKERS for the launched server
  --task-workers N         ZEALPHP_TASK_WORKERS for the launched server
  --max-conn N             Optional OpenSwoole max_conn setting
  --max-coroutine N        Optional OpenSwoole max_coroutine setting
  --backlog N              Optional OpenSwoole backlog setting
  --reactor-num N          Optional OpenSwoole reactor_num setting
  --threads N              wrk threads; capped to concurrency for small runs
  --tool auto|wrk|ab       Benchmark tool. auto prefers wrk, then ab
  --host HOST              Client host for the generated base URL
  --bind-host HOST         Server bind host when launching ZealPHP
  --port PORT              Server/client port
  --base-url URL           Existing server URL or generated URL override
  --no-start               Do not launch php app.php; test an existing server
  --php BIN                PHP binary
  --server FILE            Server entrypoint
  --output-dir DIR         CSV and raw output directory
  -h, --help               Show this help

macOS setup:
  brew install wrk

Outputs:
  CSV summary and raw tool logs are written under bench/results by default.
USAGE
}

die() {
    echo "bench.sh: $*" >&2
    exit 1
}

have() {
    command -v "$1" >/dev/null 2>&1
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

url_for_path() {
    local path="$1"
    if [[ "$path" != /* ]]; then
        path="/$path"
    fi
    printf '%s%s' "$BASE_URL" "$path"
}

server_ready() {
    curl -fsS --max-time 2 "$(url_for_path "$PROBE_PATH")" >/dev/null 2>&1
}

set_fd_limit() {
    local target=65535
    local current
    current="$(ulimit -n || true)"
    if [[ -n "$current" && "$current" != "unlimited" && "$current" -lt "$target" ]]; then
        ulimit -n "$target" 2>/dev/null || true
    fi
}

detect_tool() {
    case "$TOOL" in
        auto)
            if have wrk; then
                TOOL="wrk"
            elif have ab; then
                TOOL="ab"
            else
                die "install wrk with 'brew install wrk' or provide ApacheBench as 'ab'"
            fi
            ;;
        wrk|ab)
            have "$TOOL" || die "$TOOL not found in PATH"
            ;;
        *)
            die "unknown --tool '$TOOL' (expected auto, wrk, or ab)"
            ;;
    esac
}

start_server() {
    if [[ "$START_SERVER" -eq 0 ]]; then
        server_ready || die "server is not reachable at $BASE_URL"
        echo "Using existing server at $BASE_URL"
        return
    fi

    if server_ready; then
        echo "Using existing server at $BASE_URL"
        return
    fi

    mkdir -p "$OUTPUT_DIR/server"
    SERVER_LOG="$OUTPUT_DIR/server/zealphp-$(date +%Y%m%d-%H%M%S).log"
    SERVER_PID_FILE="$OUTPUT_DIR/server/zealphp-$PORT.pid"

    echo "Starting ZealPHP on $BIND_HOST:$PORT with $WORKERS HTTP workers and $TASK_WORKERS task workers"
    (
        cd "$ROOT_DIR"
        env_vars=(
            "ZEALPHP_HOST=$BIND_HOST"
            "ZEALPHP_PORT=$PORT"
            "ZEALPHP_WORKERS=$WORKERS"
            "ZEALPHP_TASK_WORKERS=$TASK_WORKERS"
            "ZEALPHP_BENCH_MODE=${ZEALPHP_BENCH_MODE:-1}"
            "ZEALPHP_LOG_ASYNC=${ZEALPHP_LOG_ASYNC:-1}"
            "ZEALPHP_LOG_DIR=${ZEALPHP_LOG_DIR:-/tmp/zealphp}"
            "ZEALPHP_DEBUG_LOG=${ZEALPHP_DEBUG_LOG:-0}"
            "ZEALPHP_ACCESS_LOG=${ZEALPHP_ACCESS_LOG:-0}"
            "ZEALPHP_PID_FILE=$SERVER_PID_FILE"
        )
        [[ -n "$MAX_CONN" ]] && env_vars+=("ZEALPHP_MAX_CONN=$MAX_CONN")
        [[ -n "$MAX_COROUTINE" ]] && env_vars+=("ZEALPHP_MAX_COROUTINE=$MAX_COROUTINE")
        [[ -n "$BACKLOG" ]] && env_vars+=("ZEALPHP_BACKLOG=$BACKLOG")
        [[ -n "$REACTOR_NUM" ]] && env_vars+=("ZEALPHP_REACTOR_NUM=$REACTOR_NUM")
        env "${env_vars[@]}" "$PHP_BIN" "$SERVER_FILE"
    ) >"$SERVER_LOG" 2>&1 &

    SERVER_PID="$!"
    SERVER_STARTED=1

    local attempt
    for attempt in $(seq 1 40); do
        if server_ready; then
            echo "Server ready at $BASE_URL"
            return
        fi
        sleep 0.5
    done

    echo "Server log:"
    tail -n 60 "$SERVER_LOG" >&2 || true
    die "server did not become ready at $BASE_URL"
}

stop_server() {
    if [[ "$SERVER_STARTED" -eq 1 && -n "$SERVER_PID" ]]; then
        kill "$SERVER_PID" >/dev/null 2>&1 || true
        wait "$SERVER_PID" >/dev/null 2>&1 || true
    fi
}

latency_to_ms_awk='
function to_ms(value, raw) {
    raw = value
    gsub(/^[ \t]+|[ \t]+$/, "", raw)
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
'

parse_wrk() {
    local file="$1"
    awk "$latency_to_ms_awk"'
        $1 == "Latency" && $2 != "Distribution" { avg = to_ms($2) }
        $1 == "50%" { p50 = to_ms($2) }
        $1 == "90%" { p90 = to_ms($2) }
        $1 == "99%" { p99 = to_ms($2) }
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
            printf "%.2f,%.3f,%.3f,%.3f,%.3f,%d", rps + 0, avg + 0, p50 + 0, p90 + 0, p99 + 0, failures + 0
        }
    ' "$file"
}

parse_ab() {
    local file="$1"
    awk '
        /Requests per second:/ { rps = $4 + 0 }
        /Failed requests:/ { failures = $3 + 0 }
        /Time per request:/ && avg == "" { avg = $4 + 0 }
        $1 == "50%" { p50 = $2 + 0 }
        $1 == "90%" { p90 = $2 + 0 }
        $1 == "99%" { p99 = $2 + 0 }
        END {
            printf "%.2f,%.3f,%.3f,%.3f,%.3f,%d", rps + 0, avg + 0, p50 + 0, p90 + 0, p99 + 0, failures + 0
        }
    ' "$file"
}

run_one() {
    local path="$1"
    local concurrency="$2"
    local raw_file="$3"
    local url
    url="$(url_for_path "$path")"

    if [[ "$TOOL" == "wrk" ]]; then
        local run_threads="$THREADS"
        if (( run_threads > concurrency )); then
            run_threads="$concurrency"
        fi
        wrk -t "$run_threads" -c "$concurrency" -d "$DURATION" --latency "$url" >"$raw_file" 2>&1
        parse_wrk "$raw_file"
    else
        local total_requests="$REQUESTS"
        if (( total_requests < concurrency )); then
            total_requests="$concurrency"
        fi
        ab -k -n "$total_requests" -c "$concurrency" "$url" >"$raw_file" 2>&1 || true
        parse_ab "$raw_file"
    fi
}

warmup_path() {
    local path="$1"
    if [[ "$WARMUP" == "0" || "$WARMUP" == "0s" ]]; then
        return
    fi

    local url
    url="$(url_for_path "$path")"
    echo "Warming $path for $WARMUP"

    if [[ "$TOOL" == "wrk" ]]; then
        local warm_threads="$THREADS"
        local warm_connections=50
        if (( warm_threads > warm_connections )); then
            warm_threads="$warm_connections"
        fi
        wrk -t "$warm_threads" -c "$warm_connections" -d "$WARMUP" "$url" >/dev/null 2>&1 || true
    else
        ab -k -n 1000 -c 50 "$url" >/dev/null 2>&1 || true
    fi
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --p1000|--c1000)
            CONCURRENCY_LIST="$(profile_until 1000)"
            shift
            ;;
        --max-concurrency)
            [[ $# -ge 2 ]] || die "--max-concurrency needs a value"
            CONCURRENCY_LIST="$(profile_until "$2")"
            shift 2
            ;;
        --concurrency)
            [[ $# -ge 2 ]] || die "--concurrency needs a value"
            CONCURRENCY_LIST="$2"
            shift 2
            ;;
        --path)
            [[ $# -ge 2 ]] || die "--path needs a value"
            PATHS="$2"
            shift 2
            ;;
        --paths)
            [[ $# -ge 2 ]] || die "--paths needs a value"
            PATHS="$2"
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
        --requests)
            [[ $# -ge 2 ]] || die "--requests needs a value"
            REQUESTS="$2"
            shift 2
            ;;
        --workers)
            [[ $# -ge 2 ]] || die "--workers needs a value"
            WORKERS="$2"
            shift 2
            ;;
        --task-workers)
            [[ $# -ge 2 ]] || die "--task-workers needs a value"
            TASK_WORKERS="$2"
            shift 2
            ;;
        --max-conn)
            [[ $# -ge 2 ]] || die "--max-conn needs a value"
            MAX_CONN="$2"
            shift 2
            ;;
        --max-coroutine)
            [[ $# -ge 2 ]] || die "--max-coroutine needs a value"
            MAX_COROUTINE="$2"
            shift 2
            ;;
        --backlog)
            [[ $# -ge 2 ]] || die "--backlog needs a value"
            BACKLOG="$2"
            shift 2
            ;;
        --reactor-num)
            [[ $# -ge 2 ]] || die "--reactor-num needs a value"
            REACTOR_NUM="$2"
            shift 2
            ;;
        --threads)
            [[ $# -ge 2 ]] || die "--threads needs a value"
            THREADS="$2"
            shift 2
            ;;
        --tool)
            [[ $# -ge 2 ]] || die "--tool needs a value"
            TOOL="$2"
            shift 2
            ;;
        --host)
            [[ $# -ge 2 ]] || die "--host needs a value"
            HOST="$2"
            shift 2
            ;;
        --bind-host)
            [[ $# -ge 2 ]] || die "--bind-host needs a value"
            BIND_HOST="$2"
            shift 2
            ;;
        --port)
            [[ $# -ge 2 ]] || die "--port needs a value"
            PORT="$2"
            shift 2
            ;;
        --base-url)
            [[ $# -ge 2 ]] || die "--base-url needs a value"
            BASE_URL="${2%/}"
            shift 2
            ;;
        --no-start)
            START_SERVER=0
            shift
            ;;
        --php)
            [[ $# -ge 2 ]] || die "--php needs a value"
            PHP_BIN="$2"
            shift 2
            ;;
        --server)
            [[ $# -ge 2 ]] || die "--server needs a value"
            SERVER_FILE="$2"
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

if [[ -z "$BASE_URL" ]]; then
    BASE_URL="http://$HOST:$PORT"
fi
BASE_URL="${BASE_URL%/}"

detect_tool
have curl || die "curl is required for server readiness checks"
set_fd_limit
mkdir -p "$OUTPUT_DIR/raw"

trap stop_server EXIT INT TERM
start_server

STAMP="$(date +%Y%m%d-%H%M%S)"
RESULT_CSV="$OUTPUT_DIR/zealphp-$STAMP.csv"
printf 'timestamp,tool,base_url,path,workers,task_workers,max_conn,max_coroutine,backlog,reactor_num,threads,concurrency,duration,requests,rps,avg_ms,p50_ms,p90_ms,p99_ms,failures,raw_log\n' >"$RESULT_CSV"

echo "Tool: $TOOL"
echo "Base URL: $BASE_URL"
echo "Results: $RESULT_CSV"
echo
printf '%-18s %-18s %8s %12s %10s %10s %10s %8s\n' "path" "duration" "c" "req/s" "avg_ms" "p90_ms" "p99_ms" "fail"

IFS=',' read -r -a path_array <<< "$PATHS"
IFS=',' read -r -a concurrency_array <<< "$CONCURRENCY_LIST"

for raw_path in "${path_array[@]}"; do
    path="$(trim "$raw_path")"
    [[ -n "$path" ]] || continue
    if [[ "$path" != /* ]]; then
        path="/$path"
    fi

    warmup_path "$path"

    for raw_concurrency in "${concurrency_array[@]}"; do
        concurrency="$(trim "$raw_concurrency")"
        [[ -n "$concurrency" ]] || continue

        safe_path="$(printf '%s' "$path" | sed 's#[^A-Za-z0-9._-]#_#g')"
        raw_file="$OUTPUT_DIR/raw/${STAMP}${safe_path}-c${concurrency}.txt"

        parsed="$(run_one "$path" "$concurrency" "$raw_file")"
        IFS=',' read -r rps avg_ms p50_ms p90_ms p99_ms failures <<< "$parsed"

        printf '%-18s %-18s %8s %12s %10s %10s %10s %8s\n' \
            "$path" "$DURATION" "$concurrency" "$rps" "$avg_ms" "$p90_ms" "$p99_ms" "$failures"

        timestamp="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
        printf '%s,%s,' "$timestamp" "$TOOL" >>"$RESULT_CSV"
        csv_field "$BASE_URL" >>"$RESULT_CSV"
        printf ',' >>"$RESULT_CSV"
        csv_field "$path" >>"$RESULT_CSV"
        printf ',%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,%s,' \
            "$WORKERS" "$TASK_WORKERS" "${MAX_CONN:-}" "${MAX_COROUTINE:-}" "${BACKLOG:-}" "${REACTOR_NUM:-}" \
            "$THREADS" "$concurrency" "$DURATION" "$REQUESTS" \
            "$rps" "$avg_ms" "$p50_ms" "$p90_ms" "$p99_ms" "$failures" >>"$RESULT_CSV"
        csv_field "$raw_file" >>"$RESULT_CSV"
        printf '\n' >>"$RESULT_CSV"
    done
done

echo
echo "Done. CSV: $RESULT_CSV"

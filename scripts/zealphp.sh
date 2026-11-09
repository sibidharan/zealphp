#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"
APP_FILE="${APP_FILE:-$ROOT_DIR/app.php}"
COMMAND="${1:-start}"

# Per-user default (matches resolve_log_dir() in src/utils.php): never a shared
# /tmp/zealphp, which another local user could create first to read or swap
# our logs and PID files.
default_log_dir() {
    if [[ -n "${XDG_RUNTIME_DIR:-}" ]]; then
        printf '%s/zealphp' "${XDG_RUNTIME_DIR%/}"
    else
        local tmp="${TMPDIR:-/tmp}"
        printf '%s/zealphp-%s' "${tmp%/}" "$(id -u)"
    fi
}

LOG_DIR="${ZEALPHP_LOG_DIR:-$(default_log_dir)}"
PORT="${ZEALPHP_PORT:-8080}"
PID_FILE="${ZEALPHP_PID_FILE:-$LOG_DIR/zealphp_${PORT}.pid}"
SERVER_LOG_FILE="${ZEALPHP_SERVER_LOG_FILE:-$LOG_DIR/server.log}"
ACCESS_LOG_FILE="${ZEALPHP_ACCESS_LOG_FILE:-$LOG_DIR/access.log}"
DEBUG_LOG_FILE="${ZEALPHP_DEBUG_LOG_FILE:-$LOG_DIR/debug.log}"
ZLOG_FILE="${ZEALPHP_ZLOG_FILE:-$LOG_DIR/zlog.log}"

usage() {
    cat <<'USAGE'
ZealPHP server runner

Usage:
  scripts/zealphp.sh start
  scripts/zealphp.sh restart
  scripts/zealphp.sh stop
  scripts/zealphp.sh status
  scripts/zealphp.sh logs
  scripts/zealphp.sh foreground

Defaults:
  log dir  -> $XDG_RUNTIME_DIR/zealphp, else ${TMPDIR:-/tmp}/zealphp-<uid>
  pid file -> <log dir>/zealphp_<port>.pid

`start` never stops anything: it refuses if the server is already running.
Only a process owned by you, running `php ... app.php` and listening on the
port is ever signalled; a stale or foreign PID file is reported, not acted on.

Background mode uses OpenSwoole daemonize plus file logs.
USAGE
}

die() {
    echo "zealphp.sh: $*" >&2
    exit 1
}

have() {
    command -v "$1" >/dev/null 2>&1
}

# Create $1 (0700) if missing, then insist it is a real directory owned by us
# and not group/world-writable.
ensure_private_dir() {
    local dir="$1"
    [[ -L "$dir" ]] && die "$dir is a symlink; refusing to use it"
    if [[ ! -d "$dir" ]]; then
        (umask 077 && mkdir -p "$dir") || die "cannot create $dir"
    fi
    [[ -O "$dir" ]] || die "$dir is not owned by you; set ZEALPHP_LOG_DIR to a directory you own"
    local mode
    mode="$(stat -c '%a' "$dir" 2>/dev/null || stat -f '%Lp' "$dir")"
    if (( (8#$mode & 8#022) != 0 )); then
        chmod 700 "$dir" || die "$dir is group/world-writable and cannot be tightened"
    fi
}

ensure_dirs() {
    if [[ -z "${ZEALPHP_LOG_DIR:-}" ]]; then
        ensure_private_dir "$LOG_DIR"
    else
        mkdir -p "$LOG_DIR"
    fi
    mkdir -p "$(dirname "$PID_FILE")"
    touch "$SERVER_LOG_FILE" "$ACCESS_LOG_FILE" "$DEBUG_LOG_FILE" "$ZLOG_FILE"
}

trim() {
    local value
    value="$(cat)"
    value="${value#"${value%%[![:space:]]*}"}"
    value="${value%"${value##*[![:space:]]}"}"
    printf '%s' "$value"
}

read_pid() {
    if [[ -f "$PID_FILE" ]]; then
        trim <"$PID_FILE"
    fi
}

# True when $1 is a plain positive integer > 1 naming a live process.
pid_alive() {
    local pid="$1"
    [[ "$pid" =~ ^[0-9]+$ ]] && (( pid > 1 )) || return 1
    kill -0 "$pid" >/dev/null 2>&1 && return 0
    # EPERM: alive but someone else's.
    ps -p "$pid" >/dev/null 2>&1
}

# True when $1 listens on $PORT (permissive when neither ss nor lsof exists).
pid_listens_on_port() {
    local pid="$1"
    if have ss; then
        local out
        out="$(ss -Hltnp "sport = :$PORT" 2>/dev/null || true)"
        [[ "$out" == *"pid=${pid},"* ]]
        return
    fi
    if have lsof; then
        lsof -a -p "$pid" -iTCP:"$PORT" -sTCP:LISTEN >/dev/null 2>&1
        return
    fi
    return 0
}

# True only for OUR server: a live process owned by the current uid whose argv0
# is a php binary, whose args include app.php, and which listens on $PORT.
pid_is_ours() {
    local pid="$1"
    pid_alive "$pid" || return 1
    local uid
    uid="$(ps -o uid= -p "$pid" 2>/dev/null | trim)"
    [[ "$uid" == "$(id -u)" ]] || return 1
    local argv0 args
    if [[ -r "/proc/$pid/cmdline" ]]; then
        IFS= read -r -d '' argv0 <"/proc/$pid/cmdline" || true
        args="$(tr '\0' ' ' <"/proc/$pid/cmdline")"
    else
        args="$(ps -p "$pid" -o args= 2>/dev/null | trim)"
        argv0="${args%% *}"
    fi
    [[ "$(basename -- "$argv0")" =~ ^php([0-9.].*)?$ ]] || return 1
    [[ "$args" == *"app.php"* ]] || return 1
    pid_listens_on_port "$pid"
}

port_pid() {
    if have ss; then
        ss -Hltnp "sport = :$PORT" 2>/dev/null \
            | awk -F 'pid=' '/users:\(\("php"/ { split($2, parts, ","); print parts[1]; exit }'
        return 0
    fi
    if have lsof; then
        lsof -tiTCP:"$PORT" -sTCP:LISTEN 2>/dev/null | head -n1
        return 0
    fi
    return 1
}

# Our server's master pid, from the PID file or (failing that) the port.
# A PID file naming a dead process is removed; one naming a live process that
# isn't ours is reported and left alone.
server_pid() {
    local pid
    pid="$(read_pid || true)"
    if [[ -n "$pid" ]]; then
        if pid_is_ours "$pid"; then
            printf '%s' "$pid"
            return 0
        fi
        if pid_alive "$pid"; then
            echo "zealphp.sh: $PID_FILE names pid $pid, which is not your ZealPHP server on port $PORT; ignoring it" >&2
        else
            rm -f "$PID_FILE"
        fi
    fi

    pid="$(port_pid || true)"
    if [[ -n "$pid" ]] && pid_is_ours "$pid"; then
        # The first listener found may be a worker: climb to the topmost
        # ancestor that is still our server.
        local ppid
        while ppid="$(ps -o ppid= -p "$pid" 2>/dev/null | trim)" && [[ -n "$ppid" ]] && pid_is_ours "$ppid"; do
            pid="$ppid"
        done
        printf '%s' "$pid"
        return 0
    fi

    return 1
}

wait_for_exit() {
    local pid="$1" i
    for i in $(seq 1 100); do
        pid_alive "$pid" || return 0
        sleep 0.1
    done
    return 1
}

# Live descendants of $1 owned by us (manager + workers of a master).
own_descendants() {
    local parent="$1" child
    for child in $(ps -o pid= --ppid "$parent" 2>/dev/null || pgrep -P "$parent" 2>/dev/null || true); do
        [[ "$(ps -o uid= -p "$child" 2>/dev/null | trim)" == "$(id -u)" ]] || continue
        echo "$child"
        own_descendants "$child"
    done
}

# SIGTERM the verified master (OpenSwoole shuts its manager + workers down);
# after 10 s, SIGKILL the master and its own descendants one pid at a time.
# Never signals a process group.
stop_pid() {
    local pid="$1"
    pid_is_ours "$pid" || die "pid $pid is not your ZealPHP server on port $PORT; not signalling it"

    kill -TERM "$pid" >/dev/null 2>&1 || true
    if ! wait_for_exit "$pid"; then
        local kids
        kids="$(own_descendants "$pid")"
        kill -KILL "$pid" >/dev/null 2>&1 || true
        local kid
        for kid in $kids; do
            kill -KILL "$kid" >/dev/null 2>&1 || true
        done
        wait_for_exit "$pid" || true
    fi
    rm -f "$PID_FILE"
}

start_server() {
    ensure_dirs

    local pid
    pid="$(server_pid || true)"
    if [[ -n "$pid" ]]; then
        die "server already running as pid $pid; use 'scripts/zealphp.sh restart' or 'stop' first"
    fi

    export ZEALPHP_LOG_DIR="$LOG_DIR"
    export ZEALPHP_PORT="$PORT"
    export ZEALPHP_PID_FILE="$PID_FILE"
    export ZEALPHP_SERVER_LOG_FILE="$SERVER_LOG_FILE"
    export ZEALPHP_LOG_ASYNC="${ZEALPHP_LOG_ASYNC:-1}"
    export ZEALPHP_DEBUG_LOG="${ZEALPHP_DEBUG_LOG:-0}"
    export ZEALPHP_ACCESS_LOG="${ZEALPHP_ACCESS_LOG:-1}"
    export ZEALPHP_DAEMONIZE=1

    echo "Starting ZealPHP in the background"
    (
        cd "$ROOT_DIR"
        exec "$PHP_BIN" "$APP_FILE"
    )

    local i
    for i in $(seq 1 50); do
        pid="$(read_pid || true)"
        if [[ -n "$pid" ]] && pid_is_ours "$pid"; then
            echo "Started as pid $pid"
            echo "Logs:"
            echo "  $SERVER_LOG_FILE"
            echo "  $ACCESS_LOG_FILE"
            echo "  $DEBUG_LOG_FILE"
            echo "  $ZLOG_FILE"
            return 0
        fi
        sleep 0.1
    done

    tail -n 80 "$SERVER_LOG_FILE" >&2 || true
    die "server did not come up"
}

restart_server() {
    ensure_dirs

    local pid
    pid="$(server_pid || true)"
    if [[ -n "$pid" ]]; then
        stop_pid "$pid"
    fi
    start_server
}

stop_server() {
    local pid
    pid="$(server_pid || true)"
    if [[ -z "$pid" ]]; then
        echo "ZealPHP is not running"
        return 0
    fi
    stop_pid "$pid"
    echo "Stopped pid $pid"
}

status_server() {
    local pid
    pid="$(server_pid || true)"
    if [[ -n "$pid" ]]; then
        echo "running pid $pid"
        return 0
    fi
    echo "stopped"
    return 1
}

tail_logs() {
    ensure_dirs
    exec tail -F "$SERVER_LOG_FILE" "$ACCESS_LOG_FILE" "$DEBUG_LOG_FILE" "$ZLOG_FILE"
}

foreground_server() {
    ensure_dirs

    local pid
    pid="$(server_pid || true)"
    if [[ -n "$pid" ]]; then
        die "server already running as pid $pid; run 'scripts/zealphp.sh stop' first"
    fi

    export ZEALPHP_LOG_DIR="$LOG_DIR"
    export ZEALPHP_PORT="$PORT"
    export ZEALPHP_PID_FILE="$PID_FILE"
    export ZEALPHP_LOG_ASYNC="${ZEALPHP_LOG_ASYNC:-1}"
    export ZEALPHP_DEBUG_LOG="${ZEALPHP_DEBUG_LOG:-0}"
    export ZEALPHP_ACCESS_LOG="${ZEALPHP_ACCESS_LOG:-1}"
    export ZEALPHP_DAEMONIZE=0

    cd "$ROOT_DIR"
    exec "$PHP_BIN" "$APP_FILE"
}

case "$COMMAND" in
    start)
        start_server
        ;;
    restart)
        restart_server
        ;;
    stop)
        stop_server
        ;;
    status)
        status_server
        ;;
    logs)
        tail_logs
        ;;
    foreground)
        foreground_server
        ;;
    -h|--help|help)
        usage
        ;;
    *)
        die "unknown command: $COMMAND"
        ;;
esac

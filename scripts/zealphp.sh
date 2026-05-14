#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"
APP_FILE="${APP_FILE:-$ROOT_DIR/app.php}"
COMMAND="${1:-start}"

LOG_DIR="${ZEALPHP_LOG_DIR:-/tmp/zealphp}"
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
  log dir  -> /tmp/zealphp
  pid file -> /tmp/zealphp/zealphp.pid

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

port_pid() {
    local port="$1"
    if have ss; then
        ss -ltnp "sport = :$port" 2>/dev/null \
            | awk -F 'pid=' '/users:\(\("php"/ { split($2, parts, ","); print parts[1]; exit }'
        return 0
    fi
    if have lsof; then
        lsof -tiTCP:"$port" -sTCP:LISTEN 2>/dev/null | head -n1
        return 0
    fi
    return 1
}

ensure_dirs() {
    mkdir -p "$LOG_DIR"
    mkdir -p "$(dirname "$PID_FILE")"
    touch "$SERVER_LOG_FILE" "$ACCESS_LOG_FILE" "$DEBUG_LOG_FILE" "$ZLOG_FILE"
}

read_pid() {
    if [[ -f "$PID_FILE" ]]; then
        trim <"$PID_FILE"
    fi
}

trim() {
    local value
    value="$(cat)"
    value="${value#"${value%%[![:space:]]*}"}"
    value="${value%"${value##*[![:space:]]}"}"
    printf '%s' "$value"
}

pid_cmdline() {
    local pid="$1"
    ps -p "$pid" -o args= 2>/dev/null | trim || true
}

pid_group() {
    local pid="$1"
    ps -p "$pid" -o pgid= 2>/dev/null | trim || true
}

shell_group() {
    ps -p $$ -o pgid= 2>/dev/null | trim || true
}

pid_is_running() {
    local pid="$1"
    [[ -n "$pid" ]] || return 1
    kill -0 "$pid" >/dev/null 2>&1 || return 1
    local args
    args="$(pid_cmdline "$pid")"
    [[ "$args" == *"app.php"* ]]
}

wait_for_shutdown() {
    local i pid
    for i in $(seq 1 100); do
        pid="$(server_pid || true)"
        if [[ -z "$pid" ]]; then
            rm -f "$PID_FILE"
            return 0
        fi
        sleep 0.1
    done
    return 1
}

kill_server_group() {
    local pid="$1"
    local pgid
    pgid="$(pid_group "$pid" || true)"
    if [[ -n "$pgid" ]] && [[ "$pgid" != "$(shell_group || true)" ]]; then
        kill -TERM -- "-$pgid" >/dev/null 2>&1 || true
    else
        kill -TERM "$pid" >/dev/null 2>&1 || true
    fi
}

kill_server_group_force() {
    local pid="$1"
    local pgid
    pgid="$(pid_group "$pid" || true)"
    if [[ -n "$pgid" ]] && [[ "$pgid" != "$(shell_group || true)" ]]; then
        kill -KILL -- "-$pgid" >/dev/null 2>&1 || true
    else
        kill -KILL "$pid" >/dev/null 2>&1 || true
    fi
}

cleanup_stale_pidfile() {
    local pid
    pid="$(read_pid || true)"
    if [[ -n "$pid" ]] && ! pid_is_running "$pid"; then
        rm -f "$PID_FILE"
    fi
}

server_pid() {
    local pid
    pid="$(read_pid || true)"
    if [[ -n "$pid" ]] && pid_is_running "$pid"; then
        printf '%s' "$pid"
        return 0
    fi

    pid="$(port_pid "$PORT" || true)"
    if [[ -n "$pid" ]] && pid_is_running "$pid"; then
        printf '%s' "$pid"
        return 0
    fi

    return 1
}

confirm_restart() {
    local pid="$1"
    if [[ ! -t 0 ]]; then
        die "server already running as pid $pid; run 'scripts/zealphp.sh stop' first"
    fi

    printf 'ZealPHP is already running as pid %s.\n' "$pid" >&2
    read -r -p "Kill and restart? [y/N] " answer
    case "${answer:-}" in
        y|Y|yes|YES)
            stop_pid "$pid"
            ;;
        *)
            die "aborted"
            ;;
    esac
}

stop_pid() {
    local pid="$1"
    if ! pid_is_running "$pid"; then
        rm -f "$PID_FILE"
        return 0
    fi

    kill_server_group "$pid"
    if wait_for_shutdown; then
        return 0
    fi

    kill_server_group_force "$pid"
    rm -f "$PID_FILE"
    wait_for_shutdown || true
}

start_server() {
    local force_restart="${1:-0}"
    ensure_dirs
    cleanup_stale_pidfile

    local pid
    pid="$(server_pid || true)"
    if [[ -n "$pid" ]]; then
        if [[ "$force_restart" == "1" ]]; then
            stop_pid "$pid"
        else
            confirm_restart "$pid"
        fi
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
        if [[ -n "$pid" ]] && pid_is_running "$pid"; then
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
    cleanup_stale_pidfile

    local pid
    pid="$(server_pid || true)"
    if [[ -n "$pid" ]]; then
        stop_pid "$pid"
    fi
    start_server 1
}

stop_server() {
    cleanup_stale_pidfile
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
    cleanup_stale_pidfile

    local pid
    pid="$(server_pid || true)"
    if [[ -n "$pid" ]]; then
        confirm_restart "$pid"
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

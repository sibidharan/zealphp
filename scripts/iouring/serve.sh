#!/bin/bash
# serve.sh start <so-path> [reactor]   start ZealPHP on :8080 with that openswoole.so
# serve.sh stop                        stop it (by PID: the master and its children)
# Extensions load via -d, after the conf.d ones (sockets), openswoole before zealphp.
set -u
PIDFILE=/tmp/zealphp-server.pid
case "$1" in
start)
  args=(-d "extension=$2" -d "extension=${ZEALPHP_SO:-zealphp.so}")
  if [ "${3:-}" = reactor ]; then
    args+=(-d "auto_prepend_file=$GITHUB_WORKSPACE/scripts/iouring/reactor-prepend.php")
  fi
  export ZEALPHP_APP_KEY="base64:$(php -r 'echo base64_encode(random_bytes(32));')"
  php "${args[@]}" app.php >/tmp/zealphp-server.log 2>&1 &
  echo $! > "$PIDFILE"
  for i in $(seq 1 30); do
    curl -fsS -m 5 http://127.0.0.1:8080/demo/inject/url/1 >/dev/null 2>&1 && exit 0
    sleep 1
  done
  echo "server failed to start"; tail -20 /tmp/zealphp-server.log; exit 1
  ;;
stop)
  master=$(cat "$PIDFILE" 2>/dev/null) || exit 0
  tree="$master"
  for p in $(pgrep -P "$master"); do tree="$tree $p $(pgrep -P "$p" | tr '\n' ' ')"; done
  kill -TERM "$master" 2>/dev/null
  for i in $(seq 1 20); do kill -0 "$master" 2>/dev/null || break; sleep 0.5; done
  kill -9 $tree 2>/dev/null
  for i in $(seq 1 20); do ss -ltn | grep -q ':8080 ' || break; sleep 0.5; done
  rm -f "$PIDFILE"
  ;;
esac

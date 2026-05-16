# Deploying ZealPHP

ZealPHP is a long-lived OpenSwoole server process. Unlike PHP-FPM, it does
not exit between requests — workers are forked once at startup and reused
for the life of the process. Plan your deployment accordingly: persistent
state, signal-based reloads, and a reverse proxy in front for TLS.

---

## 1. Topology

```
  [ Internet ] -> [ nginx / Caddy : 443 (TLS) ] -> [ ZealPHP : 8080 (N workers) ]
                          |
                          +--> static assets served directly
```

- **One process per port.** ZealPHP binds a single TCP port. Run multiple
  instances on different ports (`-p 8080`, `-p 8081`, ...) behind a load
  balancer if you need horizontal scaling on a single host.
- **N workers per process.** Set `ZEALPHP_WORKERS` to your CPU core count.
- **Bind a non-privileged port** (default `8080`). Let nginx/Caddy own
  `:80`/`:443` and proxy back to ZealPHP. The service user does not need
  `CAP_NET_BIND_SERVICE`.
- **Static assets** can be served by ZealPHP (`public/*`) or — for higher
  throughput — directly by the reverse proxy with an `alias` block.

---

## 2. systemd service

The repo ships a service template at `deploy/zealphp.service`:

```ini
[Unit]
Description=ZealPHP App Server
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data

WorkingDirectory=/var/www/zealphp
ExecStart=/usr/bin/php app.php

ExecStop=/bin/kill -TERM $MAINPID
KillMode=mixed
TimeoutStopSec=30s

Restart=on-failure
RestartSec=2s

LimitNOFILE=65535

# Environment="ZEALPHP_WORKERS=16"
# Environment="ZEALPHP_PORT=8080"

NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=full
ProtectHome=true

StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
```

**Why `Type=simple` and no `-d` flag?** With `Type=simple` systemd itself
tracks the master PID. The PHP process stays in the foreground, stdout and
stderr go to journald, and `Restart=on-failure` handles crashes. If you
daemonize via OpenSwoole (`-d`) the process double-forks and systemd loses
sight of the real PID.

### Install

```bash
sudo cp deploy/zealphp.service /etc/systemd/system/zealphp.service
sudoedit /etc/systemd/system/zealphp.service   # set User/Group/WorkingDirectory
sudo systemctl daemon-reload
sudo systemctl enable --now zealphp
sudo systemctl status zealphp
```

### Logs

```bash
journalctl -u zealphp -f         # tail live
journalctl -u zealphp --since '1 hour ago'
journalctl -u zealphp -p err     # errors only
```

ZealPHP's per-stream log files (`access.log`, `debug.log`) still exist
under `/tmp/zealphp/` and can be tailed independently with
`php app.php logs`.

---

## 3. Environment variables

All ZealPHP configuration is environment-driven. Set these in your
systemd unit (`Environment="..."`), Docker `-e` flags, or shell.

### Networking

| Variable | Type | Default | Purpose |
|---|---|---|---|
| `ZEALPHP_HOST` | string | `0.0.0.0` | Bind address for the HTTP server |
| `ZEALPHP_PORT` | int | `8080` | TCP port |
| `ZEALPHP_WORKERS` | int | auto (CPU cores) | HTTP worker process count |
| `ZEALPHP_TASK_WORKERS` | int | `8` | Async task worker count (set `0` to disable) |
| `ZEALPHP_MAX_REQUEST` | int | `100000` | Requests per worker before clean recycle. Bounds memory growth from long-running PHP. Set `0` to disable. |
| `ZEALPHP_MAX_CONN` | int | OpenSwoole default | `max_conn` server setting |
| `ZEALPHP_MAX_COROUTINE` | int | OpenSwoole default | `max_coroutine` server setting |
| `ZEALPHP_BACKLOG` | int | OpenSwoole default | TCP listen backlog |
| `ZEALPHP_REACTOR_NUM` | int | OpenSwoole default | Reactor thread count |

### Logging

| Variable | Type | Default | Purpose |
|---|---|---|---|
| `ZEALPHP_LOG_DIR` | path | `/tmp/zealphp` | Base directory for all log files |
| `ZEALPHP_LOG_FILE` | path | (per-stream) | Single-file override for all streams |
| `ZEALPHP_ACCESS_LOG_FILE` | path | `$LOG_DIR/access.log` | Per-request access log |
| `ZEALPHP_DEBUG_LOG_FILE` | path | `$LOG_DIR/debug.log` | `elog()` output |
| `ZEALPHP_ZLOG_FILE` | path | `$LOG_DIR/zlog.log` | `zlog()` output |
| `ZEALPHP_SERVER_LOG_FILE` | path | `$LOG_DIR/server.log` (daemon only) | OpenSwoole server log |
| `ZEALPHP_ACCESS_LOG` | bool | `1` | Toggle access logging |
| `ZEALPHP_DEBUG_LOG` | bool | `1` | Toggle debug log (`elog()`); accepts `0`/`false`/`off` |
| `ZEALPHP_LOG_ASYNC` | bool | `1` | Use coroutine channels for log writes |
| `ZEALPHP_BENCH_MODE` | bool | `0` | Disables all logging for benchmarks |

### Compression

| Variable | Type | Default | Purpose |
|---|---|---|---|
| `ZEALPHP_HTTP_COMPRESSION` | bool | `1` (auto-off if middleware enabled) | OpenSwoole's native gzip |
| `ZEALPHP_COMPRESSION_MIDDLEWARE` | bool | `0` | Register the reference `CompressionMiddleware` (only if `ZEALPHP_HTTP_COMPRESSION=0`) |

### Sessions

| Variable | Type | Default | Purpose |
|---|---|---|---|
| `ZEALPHP_SESSION_SECURE` | bool | auto-detect | Force `secure` flag on session cookie. Auto-detects via `HTTPS=on`, `X-Forwarded-Proto: https`, or `SERVER_PORT=443`. Set to `1` if your TLS terminator does not forward those headers. |

### Misc

| Variable | Type | Default | Purpose |
|---|---|---|---|
| `ZEALPHP_SITE_URL` | URL | — | Canonical site URL used by helpers and absolute-URL generation |
| `ZEALPHP_SITE_HOST` | string | — | Fallback host if `ZEALPHP_SITE_URL` is not set |
| `ZEALPHP_DAEMONIZE` | bool | `0` | OpenSwoole daemonize. Set by `scripts/zealphp.sh`; **do not set under systemd** |
| `ZEALPHP_PID_FILE` | path | `$LOG_DIR/zealphp_$PORT.pid` | PID file location |
| `ZEALPHP_DEMO_MIDDLEWARE` | bool | `0` | Enables the demo `ETag`/`CORS` middleware in `app.php`. Off in production unless you want them. |

Boolean variables accept `1`/`0`, `true`/`false`, `on`/`off`, `yes`/`no`.

---

## 4. Reverse proxy

### nginx

```nginx
upstream zealphp {
    server 127.0.0.1:8080;
    keepalive 64;
}

server {
    listen 443 ssl http2;
    server_name app.example.com;

    ssl_certificate     /etc/letsencrypt/live/app.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/app.example.com/privkey.pem;

    location / {
        proxy_pass         http://zealphp;
        proxy_http_version 1.1;

        # WebSocket upgrade
        proxy_set_header   Upgrade           $http_upgrade;
        proxy_set_header   Connection        "Upgrade";

        # Standard forwarded headers — auto-detects session cookie `secure`
        proxy_set_header   Host              $host;
        proxy_set_header   X-Real-IP         $remote_addr;
        proxy_set_header   X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;

        # SSE / streaming: do not buffer responses
        proxy_buffering    off;

        # Long-lived connections (SSE, WebSocket)
        proxy_read_timeout 86400;
        proxy_send_timeout 86400;
    }
}
```

### Caddy

```caddy
app.example.com {
    reverse_proxy 127.0.0.1:8080
}
```

Caddy handles WebSocket upgrades, automatic TLS, and HTTP/2 with no extra
configuration. For SSE, Caddy disables buffering by default — no flag
needed.

---

## 5. Docker

### Build

```bash
docker build -t zealphp:0.2.14 .
```

The shipped `Dockerfile` is PHP 8.3-cli on `bookworm` with OpenSwoole and
uopz compiled via `setup.sh --docker`. Pin extension versions with the
build args:

```bash
docker build \
    --build-arg OPENSWOOLE_VERSION=22.1.2 \
    --build-arg UOPZ_VERSION=7.1.2 \
    -t zealphp:0.2.14 .
```

### Run (single container)

```bash
docker run -d \
    -p 8080:8080 \
    -e ZEALPHP_WORKERS=16 \
    -e ZEALPHP_TASK_WORKERS=0 \
    --restart unless-stopped \
    --name zealphp \
    zealphp:0.2.14
```

### Production compose

The dev `docker-compose.yml` mounts the source tree for benchmarks. For
production, bake your app into the image and avoid volume mounts:

```yaml
services:
  app:
    image: registry.example.com/zealphp-app:0.2.14
    restart: unless-stopped
    ports:
      - "127.0.0.1:8080:8080"
    environment:
      ZEALPHP_HOST: 0.0.0.0
      ZEALPHP_PORT: 8080
      ZEALPHP_WORKERS: 16
      ZEALPHP_TASK_WORKERS: 4
      ZEALPHP_SESSION_SECURE: 1
    healthcheck:
      test: ["CMD", "php", "-r", "exit(@file_get_contents('http://127.0.0.1:8080/healthz') === 'ok' ? 0 : 1);"]
      interval: 30s
      timeout: 5s
      retries: 3
      start_period: 10s
    deploy:
      resources:
        limits:
          memory: 1g
```

Add a `/healthz` route in your app:

```php
$app->route('/healthz', fn() => 'ok');
```

---

## 6. Production checklist

- [ ] Disable debug logging: `ZEALPHP_DEBUG_LOG=0`
- [ ] `ZEALPHP_WORKERS` matches CPU cores (oversubscribing hurts more than
      it helps with coroutines)
- [ ] Run as a non-root user (the systemd template uses `www-data`)
- [ ] Bind a non-privileged port (`8080`) — never `80` or `443` directly
- [ ] Reverse proxy passes `X-Forwarded-Proto`; otherwise set
      `ZEALPHP_SESSION_SECURE=1` to force secure cookies behind HTTPS
- [ ] Session save path (`/var/lib/php/sessions`) is writable by the
      service user with mode `0700`
- [ ] `LimitNOFILE=65535` in systemd unit (already set in the template)
- [ ] Rotate logs from `/tmp/zealphp/` — see logrotate config below
- [ ] Pin OpenSwoole and uopz versions in your Dockerfile build args
- [ ] Set `ZEALPHP_TASK_WORKERS=0` if you do not use `task()` dispatch
      (saves ~8 worker processes)
- [ ] **OPcache tuned for long-running processes** — see below
- [ ] **`ZEALPHP_MAX_REQUEST` is set** (default 100000; tune for your
      leak profile; set `0` only if you've audited every static cache)

### OPcache settings for long-running workers

ZealPHP is a long-running PHP process — opcache compiles your code once
at worker startup and serves the bytecode for the rest of the worker's
life. The defaults in `php.ini` are tuned for PHP-FPM (short-lived
processes that re-check files frequently). They're wrong for our model.

**Recommended production `php.ini`:**

```ini
; opcache loaded
opcache.enable = 1
opcache.enable_cli = 1

; Sized for the codebase. 256 MB is generous for most apps; bump if
; you load a lot of templates or run a large vendor tree.
opcache.memory_consumption = 256
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 20000

; STOP checking file timestamps. Restart the server on deploy instead.
; This is the load-bearing setting: with validate_timestamps=1, every
; request pays a stat() on every file touched, which is significant under
; coroutine concurrency and gives you no benefit — workers stay alive.
opcache.validate_timestamps = 0

; Belt-and-suspenders. If you choose validate_timestamps=1 for dev
; convenience, at least keep revalidate_freq high so the stat cost is
; bounded. revalidate_freq=2 (PHP default) under load = stat storm.
opcache.revalidate_freq = 60
```

**Deploy pattern:** `php app.php restart` after deploying new code. The
manager process drains workers gracefully (current requests finish),
forks fresh workers that load the new bytecode, and the TCP listener
stays open the whole time — zero dropped requests.

The CGI bridge (legacy code via `App::includeFile()` / `proc_open`)
needs the same opcache settings to benefit. With `validate_timestamps=1`
plus a low `revalidate_freq`, a recently-edited file can serve stale
bytecode for up to `revalidate_freq` seconds after deploy — looks
identical to a logic bug. The `validate_timestamps=0` + restart pattern
above fixes it deterministically.

### Worker recycle observability

When a worker exits — for any reason: `max_request` hit, graceful
shutdown, admin reload, OOM — the server logs:

```
[recycle] worker 17 exited after 99,847 requests, peak RSS 142 MB, uptime 4831s
```

Watch your access logs for these lines. They confirm `max_request` is
working as expected and surface workers that grow much faster than
others (likely leak sources). Set `ZEALPHP_RECYCLE_LOG=0` to silence
the log line if your log volume is a concern.

### logrotate

`/etc/logrotate.d/zealphp`:

```
/tmp/zealphp/*.log {
    daily
    rotate 14
    compress
    delaycompress
    notifempty
    missingok
    copytruncate
    su www-data www-data
}
```

`copytruncate` is required because ZealPHP holds the log files open for
the lifetime of the workers — it does not reopen on `HUP`.

---

## 7. Zero-downtime restarts

OpenSwoole supports `SIGUSR1` for graceful worker reload. The master
process stays up; each worker finishes its in-flight request, then exits
and is re-forked. New code is loaded on fork.

```bash
# Reload workers (no master restart, no dropped connections)
sudo systemctl kill -s SIGUSR1 zealphp
# or:
kill -USR1 $(cat /tmp/zealphp/zealphp_8080.pid)
```

`SIGUSR2` reloads only task workers.

A full restart (drops connections briefly) is:

```bash
sudo systemctl restart zealphp
# or via the CLI manager:
php app.php restart
```

Caveat: `SIGUSR1` does not reload code held in the master process —
anything registered before `$app->run()` (e.g. `Store::make()`, route
tables) stays at the version the master booted with. For framework-level
changes, do a full restart.

---

## 8. Monitoring

### Metrics

A built-in `/metrics` endpoint is on the v0.3 roadmap. Until then, expose
metrics yourself by combining `Cache::stats()`, `Counter` values, and a
recurring `App::tick()`:

```php
use ZealPHP\App;
use ZealPHP\Cache;
use ZealPHP\Counter;

$reqs = Counter::make('http_requests_total');

App::onWorkerStart(function ($workerId) use ($reqs) {
    if ($workerId !== 0) return;        // worker 0 only
    App::tick(15_000, function () use ($reqs) {
        $lines = [
            '# TYPE http_requests_total counter',
            "http_requests_total {$reqs->get()}",
        ];
        foreach (Cache::stats() as $k => $v) {
            $lines[] = "cache_{$k} {$v}";
        }
        file_put_contents(
            '/var/lib/node_exporter/textfile/zealphp.prom',
            implode("\n", $lines) . "\n"
        );
    });
});
```

Point Prometheus node-exporter's textfile collector at
`/var/lib/node_exporter/textfile/` and you have scrape-friendly metrics
without an HTTP endpoint.

### Log shipping

For Filebeat / Vector / Promtail, tail the structured files in
`/tmp/zealphp/`:

```bash
# Filebeat input
- type: filestream
  paths:
    - /tmp/zealphp/access.log
  fields:
    service: zealphp
    stream: access
```

`access.log` lines are space-delimited (`time method status path
latency`). Parse them in your shipper's pipeline.

### Health checks

The `/healthz` pattern from section 5 is the simplest probe. For a
deeper check, hit a route that exercises your downstream dependencies
(database, cache):

```php
$app->route('/readyz', function () {
    // ping DB, cache, etc.; return 503 on failure
    return DB::ping() ? 'ok' : 503;
});
```

Kubernetes maps `/healthz` to `livenessProbe` and `/readyz` to
`readinessProbe`.

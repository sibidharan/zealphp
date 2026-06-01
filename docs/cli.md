# CLI reference

ZealPHP's server is driven by `php app.php`. The same binary starts, stops,
restarts, inspects, and tails the logs of a running instance, and supports
running several instances on different ports.

```bash
php app.php [command] [options]
```

`command` defaults to `start` when omitted, so a bare `php app.php` starts the
server in the foreground on the configured port (8080 by default).

## Commands

| Command | What it does |
|---|---|
| `start` | Start the server (the default — `php app.php` == `php app.php start`). |
| `stop` | Stop a running server. With no `-p`/`--pid-file` it stops the default (port 8080) instance; otherwise it targets the instance named by `-p`/`--pid-file`. |
| `restart` | Stop then start. If the instance was running daemonized, the restart stays daemonized unless you pass `-d`. |
| `status` | Report whether the instance is running (prints pid + port). |
| `logs` | Tail the log files (Ctrl+C to stop). Combine with the log filters below to tail specific logs. |

## Options

| Flag | Argument | Applies to | Meaning |
|---|---|---|---|
| `-p`, `--port` | `N` | all | Port to listen on / target (1–65535). Default: from `App::init()` (8080). |
| `-H`, `--host` | `ADDR` | start/restart | Listen address. Default: `0.0.0.0`. |
| `-w`, `--workers` | `N` | start/restart | Number of HTTP worker processes. |
| `-d`, `--daemonize` | — | start/restart | Run in the background (detached). |
| `--task-workers` | `N` | start/restart | Number of task workers. Default: from the server config (0 unless set). |
| `--pid-file` | `PATH` | all | Custom PID-file path (overrides the per-port default). |
| `--dev` | — | start/restart | **Enable dev route hot-reload** — each worker watches `route/*.php` and rebuilds the route table in place when a file changes, no process restart. Equivalent to `ZEALPHP_DEV=1` / `App::devReload(true)`. **Off in production** (the default). See [Hot-reload](hot-reload.md). |
| `-h`, `--help`, `help` | — | — | Print the built-in help and exit. |

### Log filters (use with `logs`)

| Flag | Tails |
|---|---|
| `--access` | `access.log` only |
| `--debug` | `debug.log` only |
| `--server` | `server.log` only |
| `--zlog` | `zlog.log` only |

Filters combine — `php app.php logs --access --debug` tails access + debug. With
no filter, `logs` tails every log file.

## Examples

```bash
php app.php                         # Start with defaults (foreground, port 8080)
php app.php --dev                   # Start with route hot-reload on (dev)
php app.php start -p 9501 -d        # Start daemonized on port 9501
php app.php start -p 9501 -d --dev  # Daemonized + hot-reload on port 9501
php app.php stop                    # Stop the default (port 8080) server
php app.php stop -p 9501            # Stop the server on port 9501
php app.php restart                 # Restart the default server
php app.php restart -p 9501         # Restart the instance on port 9501
php app.php status                  # Is the default server running?
php app.php status -p 9501          # Is the :9501 instance running?
php app.php logs                    # Tail all log files
php app.php logs --access --debug   # Tail access + debug logs
php app.php --help                  # Full built-in help
```

## PID files & multiple instances

Each instance writes a PID file named per port: `zealphp_{port}.pid` under the
runtime dir. That's how `stop` / `status` / `restart` target a specific instance —
pass the same `-p` you started it with.

**Runtime dir resolution.** The PID + log files default to **`/tmp/zealphp`**, but
that path is shared across users. If `/tmp/zealphp` already exists owned by a
*different* user (e.g. root started a server there first), ZealPHP can't write it,
so it **falls back to a per-user dir automatically** — `$XDG_RUNTIME_DIR/zealphp`
when available, otherwise `<temp>/zealphp-<uid>` (e.g. `/tmp/zealphp-1000`). The
fallback is deterministic, so `start` and `stop`/`status` always agree on the same
directory. You never need `sudo` or to change `/tmp`'s permissions. To pin an
explicit location set **`ZEALPHP_LOG_DIR=/path`** (or `ZEALPHP_PID_FILE` / the
`--pid-file` flag for just the PID file) — an explicit value always wins.

> `php app.php restart` (no `-p`) only restarts the **default** instance. If you
> run a second instance on, say, `:9501`, restart it explicitly:
> `php app.php restart -p 9501`.

## Environment variables

Most CLI options have an environment-variable equivalent so you can configure an
instance without changing `app.php`. CLI flags win over env vars; env vars win
over the `app.php` defaults. The common ones:

| Env var | Equivalent / effect | Default |
|---|---|---|
| `ZEALPHP_PORT` | listen port (like `-p`) | `8080` |
| `ZEALPHP_HOST` | listen address (like `-H`) | `0.0.0.0` |
| `ZEALPHP_WORKERS` | HTTP worker count (like `-w`) | OpenSwoole default |
| `ZEALPHP_TASK_WORKERS` | task worker count (like `--task-workers`) | `8` |
| `ZEALPHP_DAEMONIZE` | run detached (like `-d`) | `false` |
| `ZEALPHP_DEV` | enable route hot-reload (like `--dev`) | `false` |
| `ZEALPHP_PID_FILE` | custom PID-file path (like `--pid-file`) | per-port default |
| `ZEALPHP_LOG_DIR` | directory for PID + log files (explicit override) | `/tmp/zealphp`, else a per-user fallback |
| `ZEALPHP_SUPERGLOBALS` | start in superglobals mode (`App::superglobals`) | `false` |
| `ZEALPHP_PROCESS_ISOLATION` | toggle `App::processIsolation()` | follows mode |
| `ZEALPHP_ENABLE_COROUTINE` | toggle `App::enableCoroutine()` | follows mode |
| `ZEALPHP_CGI_MODE` | legacy dispatch backend (`pool` / `fcgi`) | `pool` |
| `ZEALPHP_MAX_CONN`, `ZEALPHP_MAX_COROUTINE`, `ZEALPHP_BACKLOG`, `ZEALPHP_REACTOR_NUM` | OpenSwoole server tuning | OpenSwoole defaults |

The full set of `ZEALPHP_*` knobs (logging paths, asset version, CORS origins,
etc.) is read in `app.php`; the table above is the CLI-relevant subset.

## See also

- [Hot-reload](hot-reload.md) — what `--dev` / `ZEALPHP_DEV` watch and rebuild.
- [Deployment](deployment.md) — running under systemd / Docker (no `-d`; the
  supervisor owns the process).

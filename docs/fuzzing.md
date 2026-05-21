# HTTP fuzzing & robustness harness

ZealPHP's ethos is *proven, not claimed*. The framing safety properties (RFC
9112 §6–§7) are pinned deterministically by
`tests/Integration/Http1FramingConformanceTest.php`. This document covers the
**adversarial / generative** layer that complements those fixtures: tools that
throw mutated, slow, or malformed traffic at a live server and assert the
safety invariants hold.

All harnesses target a running dev server (`php app.php` on `127.0.0.1:8080`).

| Tool | Layer | Harness | Gate? |
|------|-------|---------|-------|
| **slowhttptest** | slow-drip DoS (slowloris / RUDY) | `scripts/fuzz/slowhttptest.sh` | No — measures a known gap |
| **radamsa** | wire mutation fuzz | `scripts/fuzz/radamsa_run.sh` | **Yes** — fails on hang / trace leak |
| **gabbi** | declarative contract | `tests/gabbi/conformance.yaml` | **Yes** — fails on contract drift |
| **http-garden** | differential vs Apache/nginx | *planned, Docker-gated* | (see below) |

---

## 1. slowhttptest — slow-drip survival (mod_reqtimeout parity)

```bash
# Install (Debian/Ubuntu): apt-get install slowhttptest   (1.9.0)
# Or build:  git clone https://github.com/shekyan/slowhttptest && ./configure && make
scripts/fuzz/slowhttptest.sh both 127.0.0.1 8080
```

Opens N connections and dribbles request headers (slowloris) or body (RUDY) a
few bytes at a time, measuring how long the server holds them and whether it
stays available.

**Finding (2026-05-21, `-c 50 -i 10 -r 20 -l 30`):** all 50 slow connections
stayed `connected` for the full 30 s; `closed = 0` in both slow-headers and
slow-body modes. ZealPHP **never terminated the drip**. This is the expected
consequence of a **known documented gap**: OpenSwoole's HTTP server has no
per-request header/body read-timeout wired in (no Apache `mod_reqtimeout`
equivalent), so a slow client can pin a worker connection open. At 50
connections the worker pool isn't exhausted, so "service available" stayed YES
— but the drip-survival property is the finding.

This harness is **not a pass/fail gate** (the gap is expected and architectural).
It is the baseline against which a future read-timeout — either an OpenSwoole
setting or, the recommended production posture, a front proxy
(Traefik/Caddy/nginx) with request timeouts — can be measured.

---

## 2. radamsa — wire mutation fuzz

```bash
# Build:  git clone https://gitlab.com/akihe/radamsa /tmp/radamsa && make -C /tmp/radamsa
scripts/fuzz/radamsa_run.sh 500 127.0.0.1 8080
```

For each iteration: pick a random seed from `scripts/fuzz/corpus/*.raw` (8 raw
HTTP/1.1 requests derived from the framing fixtures — normal GET, chunked POST,
many-headers, OPTIONS, chunk-ext+trailer, query+cookie, HEAD, CL-body), mutate
its raw bytes with radamsa, replay over a raw TCP socket
(`scripts/fuzz/send_raw.py`), and classify the outcome. Mutants are written to a
temp file (not `$(...)`) so NUL/binary bytes survive into the parser.

The asserted safety invariant: every mutant reaches a **definite** outcome
inside a hard socket timeout — a clean HTTP status or a connection close. Two
outcomes fail the run:

- **HANG** — connect/read timed out (a worker stuck on our socket).
- **TRACE_5xx** — a 5xx whose body leaks a PHP stack trace / fatal (uncaught
  exception reached the client).

**Finding (2026-05-21, 500 iterations):** 334 clean 4xx rejections, 166
connection closes, **0 hangs, 0 stack-trace leaks**. (0 valid-2xx is expected —
random mutation rarely yields a still-valid request.) The OpenSwoole framing
parser is robust against random wire mutation; malformed input is consistently
rejected at the protocol layer before reaching PHP. Note radamsa sends all
bytes at once, so it does **not** exercise the slow-drip gap — that is
slowhttptest's job (§1).

---

## 3. gabbi — declarative contract suite

```bash
# Install:  pip install gabbi          (4.2.0)
gabbi-run 127.0.0.1:8080 < tests/gabbi/conformance.yaml
# Some installs ship no console_script; use the module form:
python -m gabbi.runner 127.0.0.1:8080 < tests/gabbi/conformance.yaml
```

Seven declarative request → expected-response cases pinning the well-formed
HTTP contract: `GET /json` → 200 JSON, `HEAD /json` → 200, `PUT /json` → 405 +
`Allow`, `OPTIONS /json` → 204 + `Allow`, `/.env` → 403 (dotfile policy),
unknown path → 404, positive Host contract. The malformed-framing side
(missing-Host-on-1.1 → 400 etc.) lives in the raw-socket fixtures because gabbi
always sends a well-formed request line + Host.

**Finding (2026-05-21):** 7/7 pass against the live server. No expectation
needed adjusting — every assertion matched observed-correct behavior. (Observed
quirk: the `Allow` header order differs between the 405 response
`GET, HEAD, POST, OPTIONS` and the OPTIONS response `OPTIONS, GET, HEAD, POST`;
both are valid, the suite asserts membership via regex, not order.)

---

## 4. http-garden — differential oracle (PLANNED, Docker-gated)

[http-garden](https://github.com/narfindustries/http-garden) is a differential
fuzzer: it sends the same request to many HTTP servers/proxies simultaneously
and flags **discrepancies** in how they parse it (the bug class behind most
request-smuggling CVEs — a front proxy and a back-end disagreeing on where one
request ends and the next begins). Running ZealPHP/OpenSwoole *against Apache
and nginx* would surface any framing divergence that a single-server fuzzer
(radamsa, §2) can't see by construction.

**Status: not yet wired up — gated on Docker.** http-garden orchestrates its
targets exclusively as Docker containers (one per server, driven by a Python
harness over a control socket). This host has no Docker (`command -v docker` →
nothing), and per project policy we do **not** install it here. This is the
planned next step, runnable in CI (where Docker is available) or locally on a
Docker-equipped machine.

### What adding ZealPHP as an http-garden target requires

http-garden targets live under `images/servers/<name>/`. A ZealPHP target needs:

1. **A Dockerfile** building PHP 8.3 + OpenSwoole + uopz (reuse the project's
   `setup.sh` / the `deployment.md` image), then `composer install`, exposing
   the dev server:

   ```dockerfile
   # images/servers/zealphp/Dockerfile (sketch)
   FROM php:8.3-cli
   RUN <install openswoole + uopz via setup.sh>
   COPY . /app
   WORKDIR /app
   RUN composer install --no-dev --prefer-dist
   # http-garden expects the server to read a request on stdin/socket and echo
   # its parse; the standard pattern is a thin "echo server" entrypoint that
   # boots the target and returns the normalized request line + headers it saw.
   CMD ["php", "app.php"]
   ```

2. **A garden config entry** (`config.yml`) registering the container, its
   port, and the *transducer* (the adapter that asks the server "what did you
   parse?"). For ZealPHP the simplest transducer is a dedicated echo route that
   returns the method, raw target, and the header map exactly as OpenSwoole
   parsed them — so the differential engine can compare ZealPHP's
   interpretation byte-for-byte against Apache's and nginx's.

3. **The differential run**: `./run_fuzzer.py --servers zealphp apache nginx`,
   then triage any reported discrepancy against the RFC 9112 framing rules
   already pinned in `Http1FramingConformanceTest.php`.

Until Docker is available in the target environment, this section is the
planned design; §1–§3 are the proven, executed layers.

---

## CI

`.github/workflows/fuzz.yml` (workflow_dispatch + pull_request) boots the
server, installs slowhttptest, builds radamsa, pip-installs gabbi, then runs a
bounded radamsa fuzz (300 iters) + the gabbi suite and **fails the job** on any
hang, stack-trace leak, or contract drift. slowhttptest runs informationally
(non-gating) since the slow-drip gap is expected. http-garden is the gated
follow-up once a Docker-based job is added.

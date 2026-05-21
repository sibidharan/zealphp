#!/usr/bin/env python3
"""
send_raw.py — send raw bytes from stdin to a TCP host:port and classify the
HTTP response for the radamsa wire-mutation fuzz harness (radamsa_run.sh).

Proves the framing safety property: a mutated/garbage request must produce a
*definite* outcome — a clean HTTP status (any 1xx-5xx) or a connection close —
within a hard wall-clock budget. The two FAIL classes are:

  - HANG      : connect or read timed out (the worker is stuck on our socket;
                a slow-client / framing DoS).
  - TRACE_5xx : a 5xx response whose body leaks a PHP stack trace / fatal
                (uncaught exception reached the client — info disclosure +
                un-handled parser path).

Exit code encodes the class so the bash harness can tally without parsing:
  0 = OK_2xx        (valid request mutated back into something accepted)
  1 = OK_4xx        (cleanly rejected — the desired outcome for garbage)
  2 = OK_CLOSE      (connection closed with no HTTP response — also clean)
  3 = OK_5xx_clean  (5xx but NO stack-trace marker in body — tolerated)
 10 = HANG          (FAIL: timeout)
 11 = TRACE_5xx     (FAIL: 5xx with PHP stack-trace marker)
 12 = CONNECT_FAIL  (FAIL: could not connect — server down?)

Usage:  radamsa ... | python3 send_raw.py HOST PORT [TIMEOUT_SECONDS]
"""
import re
import socket
import sys

# Substrings that indicate a PHP fatal / stack trace leaked into the body.
TRACE_MARKERS = (
    b"Stack trace:",
    b"Fatal error",
    b"Uncaught ",
    b"<b>Warning</b>",
    b"<b>Fatal error</b>",
    b"#0 ",
    b"thrown in ",
    b" on line ",
    b"PHP Stack trace",
)


def main() -> int:
    host = sys.argv[1] if len(sys.argv) > 1 else "127.0.0.1"
    port = int(sys.argv[2]) if len(sys.argv) > 2 else 8080
    timeout = float(sys.argv[3]) if len(sys.argv) > 3 else 4.0

    payload = sys.stdin.buffer.read()

    try:
        sock = socket.create_connection((host, port), timeout=timeout)
    except (socket.timeout, TimeoutError):
        return 10  # HANG on connect
    except OSError:
        return 12  # CONNECT_FAIL

    sock.settimeout(timeout)
    resp = b""
    try:
        sock.sendall(payload)
        # Half-close the write side so the server can decide to respond on
        # an otherwise complete-looking request; harmless if it ignores.
        try:
            sock.shutdown(socket.SHUT_WR)
        except OSError:
            pass
        while len(resp) < 65536:
            chunk = sock.recv(4096)
            if not chunk:
                break
            resp += chunk
    except (socket.timeout, TimeoutError):
        # We sent bytes but the server neither answered nor closed in time.
        if not resp:
            return 10  # HANG
        # Partial response then stalled — treat partial status as the verdict.
    except OSError:
        pass
    finally:
        try:
            sock.close()
        except OSError:
            pass

    if not resp:
        return 2  # OK_CLOSE (connection closed, no HTTP response)

    m = re.match(rb"HTTP/\d\.\d (\d{3})", resp)
    if not m:
        # Got bytes but no status line — server spoke non-HTTP / closed weirdly.
        return 2  # treat as clean close-ish
    status = int(m.group(1))

    if 500 <= status <= 599:
        if any(mk in resp for mk in TRACE_MARKERS):
            return 11  # TRACE_5xx (FAIL)
        return 3  # OK_5xx_clean
    if 200 <= status <= 299:
        return 0
    # 1xx / 3xx / 4xx all count as a clean definite outcome.
    return 1


if __name__ == "__main__":
    sys.exit(main())

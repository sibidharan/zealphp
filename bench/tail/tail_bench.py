#!/usr/bin/env python3
"""
Open-loop tail-latency harness for the get_labslist mongo path.

WHY OPEN-LOOP (the whole point):
  A closed-loop tool (wrk -c N, ab, hey) keeps N connections busy and sends
  the next request on a connection only after the previous response lands.
  When one async worker serializes BSON decode + FFI crossings, a closed-loop
  client *throttles itself* exactly when the server slows down — so it never
  observes the queue that's actually forming behind that worker. Median and
  even p99 look fine; the real tail is invisible. This is "coordinated
  omission" (Gil Tene).

  This harness instead fires at a fixed ARRIVAL RATE regardless of how many
  requests are still in flight, and measures each request's latency from its
  INTENDED send time, not the moment it actually went out. If the single
  worker is saturated, requests pile up and that wait is fully attributed —
  which is what a real client population would experience.

WHAT IT PRODUCES:
  A rate sweep table. Below saturation the queue is empty and the tail is
  flat. As arrival rate approaches and crosses the worker's service ceiling
  (your measured ~556 req/s), p99.9 diverges while p50 barely moves. The rate
  at which the tail explodes IS the answer to "what's the contention shape."

USAGE:
  pip install aiohttp
  python3 tail_bench.py --url http://LABS_HOST:PORT/get_labslist \\
      --sweep 200,400,500,556,600,700 --duration 60 --warmup 5 --arrival poisson

  # POST with body + auth header:
  python3 tail_bench.py --url http://host/get_labslist --method POST \\
      --header 'authorization: Bearer XXX' --body '{"page":1}' --sweep 500,556,650

  # CSV of every sample for offline HdrHistogram / plotting:
  python3 tail_bench.py --url ... --sweep 556 --csv samples_556.csv
"""
from __future__ import annotations

import argparse
import asyncio
import json
import random
import sys
import time
from dataclasses import dataclass, field

try:
    import aiohttp
except ImportError:
    sys.exit("aiohttp required: pip install aiohttp")


@dataclass
class RateResult:
    rate: float
    scheduled: int = 0
    sent: int = 0
    ok: int = 0
    err: int = 0
    # latency in milliseconds, measured from INTENDED send time
    latencies: list[float] = field(default_factory=list)
    max_inflight: int = 0
    status_counts: dict[int, int] = field(default_factory=dict)


def percentile(sorted_vals: list[float], p: float) -> float:
    """Nearest-rank percentile on an already-sorted list."""
    if not sorted_vals:
        return float("nan")
    k = max(0, min(len(sorted_vals) - 1, int(round((p / 100.0) * len(sorted_vals) + 0.5)) - 1))
    return sorted_vals[k]


async def run_rate(
    session: aiohttp.ClientSession,
    url: str,
    method: str,
    body: bytes | None,
    headers: dict[str, str],
    rate: float,
    duration: float,
    warmup: float,
    arrival: str,
    timeout_s: float,
) -> RateResult:
    """Fire at a constant arrival `rate` for `duration` seconds, open-loop."""
    res = RateResult(rate=rate)
    inflight = 0
    t0 = time.perf_counter()
    warmup_until = t0 + warmup
    end = t0 + warmup + duration

    async def one_request(scheduled_at: float, counts: bool) -> None:
        nonlocal inflight
        inflight += 1
        res.max_inflight = max(res.max_inflight, inflight)
        try:
            async with session.request(
                method, url, data=body, headers=headers,
                timeout=aiohttp.ClientTimeout(total=timeout_s),
            ) as resp:
                await resp.read()  # drain body — BSON decode happens server-side regardless
                status = resp.status
            if counts:
                res.status_counts[status] = res.status_counts.get(status, 0) + 1
                # coordinated-omission-correct: measure from INTENDED send time
                lat_ms = (time.perf_counter() - scheduled_at) * 1000.0
                res.latencies.append(lat_ms)
                if 200 <= status < 400:
                    res.ok += 1
                else:
                    res.err += 1
        except Exception:
            if counts:
                res.err += 1
                res.latencies.append((time.perf_counter() - scheduled_at) * 1000.0)
        finally:
            inflight -= 1

    tasks: list[asyncio.Task[None]] = []
    next_at = t0
    interval = 1.0 / rate
    while True:
        now = time.perf_counter()
        if next_at >= end:
            break
        sleep_for = next_at - now
        if sleep_for > 0:
            await asyncio.sleep(sleep_for)
        scheduled = next_at
        counts = scheduled >= warmup_until  # discard warmup samples
        res.scheduled += 1
        if counts:
            res.sent += 1
        tasks.append(asyncio.create_task(one_request(scheduled, counts)))
        # advance schedule. poisson = exponential gaps (bursty, realistic);
        # uniform = fixed cadence (cleanest saturation probe).
        if arrival == "poisson":
            next_at += random.expovariate(rate)
        else:
            next_at += interval

    # let in-flight requests drain (bounded by timeout)
    if tasks:
        await asyncio.gather(*tasks, return_exceptions=True)
    return res


def fmt_ms(v: float) -> str:
    if v != v:  # nan
        return "-"
    if v >= 1000:
        return f"{v/1000:.2f}s"
    return f"{v:.1f}ms"


def print_table(results: list[RateResult]) -> None:
    cols = ["rate", "sent", "ok", "err", "maxInflt", "p50", "p90", "p99", "p99.9", "p99.99", "max"]
    widths = [8, 8, 7, 6, 9, 8, 8, 9, 9, 9, 9]
    header = "  ".join(c.ljust(w) for c, w in zip(cols, widths))
    print("\n" + header)
    print("-" * len(header))
    for r in results:
        s = sorted(r.latencies)
        row = [
            f"{r.rate:g}",
            str(r.sent),
            str(r.ok),
            str(r.err),
            str(r.max_inflight),
            fmt_ms(percentile(s, 50)),
            fmt_ms(percentile(s, 90)),
            fmt_ms(percentile(s, 99)),
            fmt_ms(percentile(s, 99.9)),
            fmt_ms(percentile(s, 99.99)),
            fmt_ms(s[-1] if s else float("nan")),
        ]
        print("  ".join(c.ljust(w) for c, w in zip(row, widths)))
    print()
    print("Read it like this: p50 stays flat while p99.9 climbs = the single")
    print("worker is queueing. The rate where p99.9 first knees upward is the")
    print("real service ceiling, NOT the throughput number. maxInflt blowing")
    print("up means arrivals are outrunning the worker (the tail you're after).")


async def main() -> None:
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument("--url", required=True)
    ap.add_argument("--method", default="GET")
    ap.add_argument("--header", action="append", default=[], help="repeatable: 'Key: Value'")
    ap.add_argument("--body", default=None)
    ap.add_argument("--sweep", default="556", help="comma-separated arrival rates, req/s")
    ap.add_argument("--duration", type=float, default=60.0, help="measured seconds per rate")
    ap.add_argument("--warmup", type=float, default=5.0, help="discarded warmup seconds per rate")
    ap.add_argument("--arrival", choices=["poisson", "uniform"], default="poisson")
    ap.add_argument("--timeout", type=float, default=30.0, help="per-request timeout seconds")
    ap.add_argument("--max-conns", type=int, default=512,
                    help="keep-alive connection pool size. Large enough that the CLIENT never "
                         "bottlenecks below the server's ceiling. If maxInflt in the output "
                         "approaches this number, raise it — the pool, not the worker, is the limit.")
    ap.add_argument("--cooldown", type=float, default=3.0, help="idle seconds between rates (let queue drain)")
    ap.add_argument("--csv", default=None, help="write every sample (rate,latency_ms) to this file")
    args = ap.parse_args()

    headers: dict[str, str] = {}
    for h in args.header:
        if ":" in h:
            k, v = h.split(":", 1)
            headers[k.strip()] = v.strip()
    body = args.body.encode() if args.body else None
    rates = [float(x) for x in args.sweep.split(",") if x.strip()]

    print(f"target   : {args.method} {args.url}")
    print(f"sweep    : {rates} req/s   ({args.arrival} arrivals)")
    print(f"duration : {args.duration}s measured + {args.warmup}s warmup per rate")
    print("model    : OPEN-LOOP, latency from intended send time (coordinated-omission corrected)")

    results: list[RateResult] = []
    # Large keep-alive pool, NOT limit=0. Two reasons:
    #  1. keep-alive reuse isolates mongo-path service time from per-request
    #     TCP handshake cost (and avoids the connection storm / port exhaustion
    #     that unlimited fresh connections cause under burst).
    #  2. the pool is sized big enough that the client never throttles below
    #     the server's ceiling. If it DOES saturate, maxInflt surfaces it so
    #     you know to raise --max-conns rather than mis-reading client-side
    #     queueing as server tail.
    connector = aiohttp.TCPConnector(
        limit=args.max_conns, limit_per_host=args.max_conns,
        ttl_dns_cache=300, keepalive_timeout=60,
    )
    async with aiohttp.ClientSession(connector=connector) as session:
        for rate in rates:
            print(f"\n>>> rate {rate:g} req/s ...", flush=True)
            r = await run_rate(session, args.url, args.method, body, headers,
                               rate, args.duration, args.warmup, args.arrival, args.timeout)
            results.append(r)
            if r.status_counts:
                print(f"    statuses: {dict(sorted(r.status_counts.items()))}  maxInflight={r.max_inflight}")
            await asyncio.sleep(args.cooldown)

    print_table(results)

    if args.csv:
        with open(args.csv, "w") as f:
            f.write("rate,latency_ms\n")
            for r in results:
                for lat in r.latencies:
                    f.write(f"{r.rate:g},{lat:.4f}\n")
        print(f"samples written to {args.csv}")


if __name__ == "__main__":
    try:
        asyncio.run(main())
    except KeyboardInterrupt:
        pass

# Tail-latency bench for the `get_labslist` mongo path

Measures what `p95 per-operation` and `wrk -c 64` **can't**: the p99.9 / p99.99
latency a real client population sees when arrivals queue behind the single
async worker (BSON decode + FFI crossing, serialized).

## The one idea that matters: open-loop, not closed-loop

A closed-loop tool (`wrk -c N`, `ab`, `hey`) keeps N connections busy and
sends the next request on a connection only **after** the previous response
returns. So when the single worker stalls, the load generator stalls with it
— it stops sending exactly when the queue is forming. The measurement
throttles itself in lockstep with the bottleneck. Median and p99 look fine;
the real tail never appears. This is **coordinated omission** (Gil Tene).

To see the tail behind a serialized resource you need:

1. **Open-loop arrivals** — fire at a fixed rate regardless of how many
   requests are still in flight. If the worker can't keep up, in-flight piles
   up; that's the signal, not something to smooth away.
2. **Latency from the *intended* send time**, not the actual send time. A
   request that was supposed to go out at T but couldn't until T+50ms (because
   the client or server was busy) has 50ms of latency already, before the
   server even starts. Closed-loop tools never count this.
3. **A rate sweep** around the measured ceiling (~556 req/s). Below it the
   queue is empty and the tail is flat. As arrival rate crosses the worker's
   service rate, queue depth grows without bound and p99.9 diverges while p50
   barely moves. **The rate where p99.9 knees upward is the real ceiling** —
   throughput (556) only tells you the worker's mean service rate, not where
   it falls over for tail-sensitive clients.

## Run it

```bash
python3 -m venv .venv
./.venv/bin/pip install aiohttp
./.venv/bin/python tail_bench.py \
    --url http://LABS_HOST:PORT/get_labslist \
    --sweep 200,400,500,556,600,700 \
    --duration 60 --warmup 5 --arrival poisson
```

`--arrival poisson` = exponential inter-arrival gaps (bursty, realistic — the
right choice for "what does p99.9 do under burst"). `--arrival uniform` =
fixed cadence (cleaner for pinpointing the saturation knee).

POST with body + auth:

```bash
./.venv/bin/python tail_bench.py --url http://host/get_labslist --method POST \
    --header 'authorization: Bearer XXX' --body '{"page":1,"limit":50}' \
    --sweep 500,556,650
```

Dump every sample for offline HdrHistogram / plotting:

```bash
./.venv/bin/python tail_bench.py --url ... --sweep 556 --csv samples_556.csv
```

## Reading the output

```
rate    sent   ok    err  maxInflt  p50    p90    p99    p99.9   p99.99  max
200     12000  12000 0    3         3.1ms  5.3ms  9.0ms  14ms    14ms    14ms
500     30000  30000 0    7         2.7ms  3.5ms  4.9ms  6.7ms   8.3ms   8.3ms
556     33360  33310 50   140       4.5ms  18ms   142ms  980ms   2.1s    3.4s   <- knee
650     39000  31000 8000 512*      9ms    1.2s   6s     timeout timeout timeout
```

- **p50 flat while p99.9 climbs** = the single worker is queueing. This is the
  contention shape Deep is asking about.
- **`maxInflt` approaching `--max-conns`** = either the worker is saturated
  (the answer) OR the client pool is the bottleneck. Disambiguate by raising
  `--max-conns` and re-running: if the tail moves, it was the client; if it
  doesn't, it's the worker. `*` above means the pool was pegged — raise it.
- **`err` climbing** = the worker's queue exceeded the request timeout, or the
  accept backlog overflowed. Both are real failure modes worth reporting.

## Methodology guards (so the numbers survive scrutiny)

- **Keep-alive pool, sized large** (`--max-conns 512`): connection reuse keeps
  TCP-handshake cost out of the measured latency, so you're timing the mongo
  path, not `connect()`. Sized big enough that the client never throttles
  below the server's ceiling.
- **Warmup discarded** (`--warmup`): first N seconds (cold pool, JIT, cold
  mongo cursor / connection cache) don't enter the percentiles.
- **Sample count**: p99.9 needs ≳10k samples to be stable; p99.99 needs
  ≳100k. At 556 req/s, 60s = ~33k samples — solid for p99.9, marginal for
  p99.99. Bump `--duration` to 300 if you want trustworthy p99.99.
- **Cooldown between rates** (`--cooldown`): lets the worker's queue fully
  drain so one rate's backlog doesn't bleed into the next.

## Cross-check with an off-the-shelf tool

Don't trust one harness. `wrk2` and `vegeta` are both open-loop /
coordinated-omission-correct and will corroborate the shape:

```bash
# wrk2 (Gil Tene's fork — constant -R rate, HdrHistogram to p99.999)
wrk2 -t4 -c128 -d60s -R556 --latency http://host/get_labslist

# vegeta (open-loop by construction)
echo "GET http://host/get_labslist" | \
  vegeta attack -rate=556 -duration=60s | vegeta report -type='hdrplot'
```

Plain `wrk` (no `2`) and `ab` are closed-loop — **do not** use them for the
tail; they'll under-report it by an order of magnitude under saturation.

## Isolating the FFI/BSON worker specifically

The whole point is the single serialized worker. To attribute tail to it
rather than to network or the rest of the stack:

- Run the sweep against `get_labslist` AND against a route that returns a
  static payload (no mongo / FFI). The static route's tail is your floor; the
  delta is what the serialized worker adds.
- If you can, add a server-side timer around just the BSON-decode + FFI span
  and export it (per-request, with the request id) so you can correlate the
  client-observed p99.9 with worker queue depth at that instant.

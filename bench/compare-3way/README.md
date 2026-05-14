# 3-way comparison bench: ZealPHP vs raw Node vs Express

Measures `/raw/bench` throughput across the three stacks at the same
worker count, concurrency, and over 10 separated samples so noise averages out.

## Files

- `run.sh` — orchestrator. Starts all three servers on separate ports, runs
  `autocannon` against each in turn for `$ITERATIONS` rounds with a `$GAP`
  pause between rounds (each iteration is a "different moment in time" so
  machine state varies), then prints mean/stddev per stack.
- `node_raw.js` — `http.createServer()` only, `cluster.fork()` for parallelism.
- `node_express.js` — Express with `x-powered-by` and `etag` disabled.
- ZealPHP is run from the repo root with `ZEALPHP_BENCH_MODE=1`,
  `ZEALPHP_ACCESS_LOG=0`, `ZEALPHP_DEBUG_LOG=0`.

## Prerequisites

```bash
cd /tmp && npm install autocannon express
```

## Run

```bash
./bench/compare-3way/run.sh
```

Knobs at the top of `run.sh`:

| var          | default | meaning                          |
|--------------|---------|----------------------------------|
| `WORKERS`    | 4       | workers per stack                |
| `CONNECTIONS`| 200     | autocannon -c                    |
| `DURATION`   | 10      | seconds per sample               |
| `ITERATIONS` | 10      | samples per stack                |
| `GAP`        | 20      | seconds between iterations       |
| `PATHX`      | /raw/bench | endpoint hit                  |

## Output

- Per-iteration table (live, on stdout)
- `$OUT/samples.csv` — `iteration,stack,req_per_sec,avg_latency_ms,p50_ms,p90_ms,p99_ms,total_2xx`
- `$OUT/iter${i}_${stack}.json` — raw autocannon output per sample
- Aggregate table with mean ± stddev printed at the end

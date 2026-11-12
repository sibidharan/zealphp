#!/bin/bash
# bench.sh <label>: ab against three routes in three shapes; one line each.
# -s 10 bounds a stuck request; a failed ab is recorded, never fatal.
label=$1
for path in /demo/inject/url/42 / /docs/guide/routing; do
  for shape in "-c 1 -k" "-c 64" "-c 64 -k"; do
    out=$(ab -q -s 10 $shape -n 3000 "http://127.0.0.1:8080$path" 2>&1); rc=$?
    rps=$(echo "$out" | awk '/Requests per second/ {print $4}')
    done_n=$(echo "$out" | awk '/Complete requests/ {print $3}')
    non2xx=$(echo "$out" | awk '/Non-2xx/ {print $3}')
    err=$(echo "$out" | grep -E '^apr_|Total of' | head -1)
    alive=$(curl -fsS -m 5 -o /dev/null -w '%{http_code}' http://127.0.0.1:8080/demo/inject/url/1 2>/dev/null || echo dead)
    estab=$(ss -tn state established '( sport = :8080 )' 2>/dev/null | tail -n +2 | wc -l)
    echo "$label $path [$shape] rc=$rc rps=${rps:-none} complete=${done_n:-0} non2xx=${non2xx:-0} after=$alive estab=$estab $err"
  done
done

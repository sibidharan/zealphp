#!/bin/sh
# Print how many io_uring rings each ZealPHP server process holds, plus how
# many io_uring warnings the server logged. Proves the run used io_uring.
master=$(cat /tmp/zealphp-server.pid 2>/dev/null)
[ -n "$master" ] || { echo "no server pid"; exit 0; }
for pid in $master $(pgrep -P "$master") $(for c in $(pgrep -P "$master"); do pgrep -P "$c"; done); do
  rings=$(ls -l /proc/"$pid"/fd 2>/dev/null | grep -c 'io_uring')
  echo "pid $pid ($(tr '\0' ' ' < /proc/"$pid"/cmdline | cut -c1-40)): io_uring rings=$rings"
done | sort -u
echo "server log io_uring warnings: $(grep -c 'io_uring' /tmp/zealphp-server.log || true)"

#!/usr/bin/env python3
import sys, time
body = sys.stdin.read()
print("Content-Type: text/plain\r\n\r")
for i in range(3):
    print(f"chunk {i} body={body!r}", flush=True)
    time.sleep(0.3)

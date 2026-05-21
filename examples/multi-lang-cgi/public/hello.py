#!/usr/bin/env python3
"""Minimal Python CGI script — CGI/1.1 output format."""
import os
import sys

print("Content-Type: text/html")
print("")
print("<!DOCTYPE html>")
print("<html><head><title>Python CGI</title></head><body>")
print("<h1>Hello from Python CGI!</h1>")
print("<p>Script: " + os.environ.get("SCRIPT_FILENAME", "unknown") + "</p>")
print("<p>Method: " + os.environ.get("REQUEST_METHOD", "unknown") + "</p>")
print("</body></html>")

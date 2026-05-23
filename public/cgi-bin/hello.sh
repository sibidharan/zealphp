#!/bin/bash
echo "Content-Type: text/plain"
echo ""
printf 'EXECUTED-AT-EPOCH-%s\n' "$(date +%s)"
printf 'REQUEST_METHOD=%s\n' "${REQUEST_METHOD:-?}"
printf 'QUERY_STRING=%s\n' "${QUERY_STRING:-(none)}"

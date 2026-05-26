#!/bin/bash

COMBOS=(
    "NAME='0.3.0 Coroutine Superglobals' G=true C=true I=false"
    "NAME='Legacy Sync' G=true C=false I=false"
    "NAME='Standard ZealPHP' G=false C=true I=false"
)

APPS=("superglobals_basic" "superglobals_sessions" "superglobals_cookies" "superglobals_headers" "superglobals_apache" "superglobals_files" "superglobals_buffering" "superglobals_error" "superglobals_shutdown" "mini_cms")

for combo in "${COMBOS[@]}"; do
    eval export $combo
    echo "=== Testing: $NAME ==="
    
    docker run -d --name zealphp_test -p 8181:8080 \
        -e ZEALPHP_SUPERGLOBALS=$G \
        -e ZEALPHP_ENABLE_COROUTINE=$C \
        -e ZEALPHP_PROCESS_ISOLATION=$I \
        zealphp:local > /dev/null
    
    sleep 3
    
    for app in "${APPS[@]}"; do
        echo -n "Testing $app... "
        URL="http://localhost:8181/$app"
        case $app in
            "superglobals_basic")
                RESP=$(curl -s -X POST -d "foo=bar" "$URL?baz=qux")
                if [[ $RESP == *"baz"* && $RESP == *"qux"* && $RESP == *"foo"* && $RESP == *"bar"* ]]; then echo "OK"; else echo "FAIL ($RESP)"; fi
                ;;
            "superglobals_sessions")
                curl -s -c sess.txt "$URL" > /dev/null
                RESP=$(curl -s -b sess.txt "$URL")
                if [[ $RESP == *"Count: 2"* ]]; then echo "OK"; else echo "FAIL ($RESP)"; fi
                rm -f sess.txt
                ;;
            "superglobals_cookies")
                curl -s -c cook.txt "$URL?set=1" > /dev/null
                RESP=$(curl -s -b cook.txt "$URL")
                if [[ $RESP == *"123"* ]]; then echo "OK"; else echo "FAIL ($RESP)"; fi
                rm -f cook.txt
                ;;
            "superglobals_headers")
                RESP_HEADERS=$(curl -s -I "$URL")
                if [[ $RESP_HEADERS == *"X-Test: 1"* && $RESP_HEADERS == *"HTTP/1.1 201"* ]]; then echo "OK"; else echo "FAIL"; fi
                ;;
            "superglobals_files")
                RESP=$(curl -s -F "test=@/etc/hostname" "$URL")
                if [[ $RESP == *"FILE:"* ]]; then echo "OK"; else echo "FAIL ($RESP)"; fi
                ;;
            "superglobals_error")
                RESP=$(curl -s "$URL")
                if [[ $RESP == *"ERR:TEST"* ]]; then echo "OK"; else echo "FAIL ($RESP)"; fi
                ;;
            "superglobals_shutdown")
                RESP=$(curl -s "$URL")
                if [[ $RESP == *"RUN"* && $RESP == *"SHUTDOWN"* ]]; then echo "OK"; else echo "FAIL ($RESP)"; fi
                ;;
            "mini_cms")
                curl -s -c cms.txt "$URL?user=ZealUser" > /dev/null
                RESP=$(curl -s -b cms.txt "$URL")
                if [[ $RESP == *"User: Guest"* && $RESP == *"Session: ZealUser"* ]]; then echo "OK"; else echo "FAIL ($RESP)"; fi
                rm -f cms.txt
                ;;
            *)
                RESP=$(curl -s "$URL")
                if [ -n "$RESP" ]; then echo "OK"; else echo "FAIL (Empty)"; fi
                ;;
        esac
    done
    
    docker rm -f zealphp_test > /dev/null
    echo ""
done

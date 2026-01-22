#!/bin/bash
# /usr/local/bin/ipsec-json-safe.sh

SRC="/var/run/ipsec-status.json"
TMP="$(mktemp /tmp/ipsec-status.XXXXXX.json)"
MAX_TRIES=5
SLEEP_SEC=0.2
TUN_NAME="${1:-bejdove}"

cleanup() { rm -f "$TMP"; }
trap cleanup EXIT

try=1
while [ "$try" -le "$MAX_TRIES" ]; do
    if ! cp "$SRC" "$TMP" 2>/dev/null; then
        try=$((try+1))
        sleep "$SLEEP_SEC"
        continue
    fi

    # validní JSON?
    if jq -e . "$TMP" >/dev/null 2>&1; then
        # vra jeden tunel jako JSON na stdout
    cp "$TMP" /tmp/ipsec-json-debug.txt
        jq -r --arg NAME "$TUN_NAME" \
          '.tunnels[] | select(.name==$NAME) | @json' "$TMP"
        exit 0
    fi

    try=$((try+1))
    sleep "$SLEEP_SEC"
done

echo "ERROR: invalid JSON after $MAX_TRIES tries" >&2
exit 1

#!/bin/bash

TUN="bejdove"
LOG="/var/log/ipsec-tunnel-monitor.log"
STATE_FILE="/var/run/ipsec-tunnel-monitor.state"

mkdir -p "$(dirname "$LOG")"

while true; do
    ts="$(date -Iseconds)"

    json="$(/usr/local/bin/ipsec-json-safe.sh "$TUN" 2>/tmp/ipsec-json-safe.err)"
    rc=$?

    if [ $rc -ne 0 ] || [ -z "$json" ]; then
        echo "$ts ERROR: safe.sh failed rc=$rc msg=$(cat /tmp/ipsec-json-safe.err)" >>"$LOG"
        sleep 10
        continue
    fi

    pkts_in=$(echo "$json" | jq -r '.packets_in')
    pkts_out=$(echo "$json" | jq -r '.packets_out')

    # pøedchozí hodnoty
    prev_in=0 prev_out=0
    if [ -f "$STATE_FILE" ]; then
        read prev_in prev_out <"$STATE_FILE"
    fi

    echo "$pkts_in $pkts_out" >"$STATE_FILE"

    echo "$ts OK: in=$pkts_in out=$pkts_out ?in=$((pkts_in-prev_in)) ?out=$((pkts_out-prev_out))" >>"$LOG"

    sleep 10
done

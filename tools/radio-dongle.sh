#!/usr/bin/env bash
#
# Start and stop the RTL-SDR dongle's network server, for a machine that only listens to
# the radio now and then.
#
# rtl_tcp claims the USB device for as long as it runs, whether or not anything is tuned,
# which leaves the dongle powered and warm. It has no idle timeout and no way to be started
# on demand: it binds its own socket, so launchd cannot hand it one. Until something
# supervises it, starting and stopping it by hand is the whole of the answer.
#
#   tools/radio-dongle.sh start     bring it up, and wait until it is actually listening
#   tools/radio-dongle.sh stop      shut it down and release the dongle
#   tools/radio-dongle.sh status    say whether it is up and whether anything is listening
#
# Skywave reaches it through RADIO_RTL_TCP; see the HD Radio section of the README.

set -u

ADDRESS="${RADIO_DONGLE_ADDRESS:-127.0.0.1}"
PORT="${RADIO_DONGLE_PORT:-1234}"

running() {
    pgrep -f "rtl_tcp -a $ADDRESS" >/dev/null 2>&1
}

listening() {
    lsof -nP -iTCP:"$PORT" -sTCP:LISTEN >/dev/null 2>&1
}

clients() {
    lsof -nP -iTCP:"$PORT" -sTCP:ESTABLISHED 2>/dev/null | tail -n +2 | wc -l | tr -d ' '
}

start() {
    if running; then
        echo "Already running on $ADDRESS:$PORT."

        return 0
    fi

    if ! command -v rtl_tcp >/dev/null 2>&1; then
        echo "rtl_tcp is not on PATH. Install librtlsdr first; see the README." >&2

        return 1
    fi

    local log="${TMPDIR:-/tmp}/rtl_tcp.log"
    rtl_tcp -a "$ADDRESS" -p "$PORT" >"$log" 2>&1 &

    # It opens the USB device before it listens, and fails there if something else holds it.
    for _ in $(seq 1 20); do
        sleep 0.5
        if listening; then
            echo "Listening on $ADDRESS:$PORT. The dongle is powered until you stop it."

            return 0
        fi

        if grep -q "Failed to open\|usb_claim_interface" "$log" 2>/dev/null; then
            echo "Could not open the dongle:" >&2
            tail -4 "$log" >&2

            return 1
        fi
    done

    echo "Started, but it is not listening after ten seconds. See $log." >&2

    return 1
}

stop() {
    if ! running; then
        echo "Not running."

        return 0
    fi

    local waiting=$(clients)
    if [ "$waiting" != "0" ]; then
        echo "Something is still listening to it; stopping anyway."
    fi

    # Deliberately -9. A plain TERM was tried twice here and left it running both times,
    # which is worse than abrupt: the next start cannot claim the device and fails with
    # usb_claim_interface error -3, naming nothing that explains it.
    pkill -9 -f "rtl_tcp -a $ADDRESS" 2>/dev/null

    for _ in $(seq 1 10); do
        sleep 0.3
        if ! running; then
            echo "Stopped. The dongle is released and will cool."

            return 0
        fi
    done

    echo "It is still running. Try: pkill -9 -f rtl_tcp" >&2

    return 1
}

status() {
    if running; then
        echo "rtl_tcp is running on $ADDRESS:$PORT ($(clients) connected). The dongle is powered."
    else
        echo "rtl_tcp is not running. The dongle is idle, and the radio will say it cannot reach a server."
    fi
}

case "${1:-status}" in
    start) start ;;
    stop) stop ;;
    status) status ;;
    restart) stop && start ;;
    *)
        echo "Usage: $0 {start|stop|status|restart}" >&2
        exit 64
        ;;
esac

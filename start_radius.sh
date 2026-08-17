#!/usr/bin/env bash
# start_radius.sh — start/stop/restart the RADIUS daemon without systemd.
#
# Useful on hosts where you cannot install a unit file. On a VPS prefer
# deploy/mangonet-radius.service, which restarts the daemon automatically.
#
#   bash start_radius.sh start|stop|restart|status
set -u

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PHP_BIN="${PHP_BIN:-php}"
RADIUS_SCRIPT="${SCRIPT_DIR}/radius_server.php"
# The daemon writes and rotates logs/radius.log itself. This file captures only
# startup failures on stderr, so it stays tiny.
LOG_FILE="${SCRIPT_DIR}/logs/radius-startup.log"
PID_FILE="${SCRIPT_DIR}/logs/radius.pid"

mkdir -p "${SCRIPT_DIR}/logs"

is_running() {
    [ -f "$PID_FILE" ] || return 1
    local pid
    pid="$(cat "$PID_FILE" 2>/dev/null)"
    [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null
}

start() {
    if is_running; then
        echo "RADIUS daemon already running (PID $(cat "$PID_FILE"))"
        return 0
    fi
    nohup "$PHP_BIN" "$RADIUS_SCRIPT" >> "$LOG_FILE" 2>&1 &
    echo $! > "$PID_FILE"
    sleep 1
    if is_running; then
        echo "RADIUS daemon started (PID $(cat "$PID_FILE"))"
    else
        echo "RADIUS daemon failed to start. Last startup output:"
        tail -n 15 "$LOG_FILE"
        echo "--- and the daemon log: ---"
        tail -n 15 "${SCRIPT_DIR}/logs/radius.log" 2>/dev/null
        return 1
    fi
}

stop() {
    if ! is_running; then
        echo "RADIUS daemon is not running"
        rm -f "$PID_FILE"
        return 0
    fi
    local pid
    pid="$(cat "$PID_FILE")"
    kill "$pid"
    rm -f "$PID_FILE"
    echo "RADIUS daemon stopped (PID $pid)"
}

status() {
    if is_running; then
        echo "RADIUS daemon is running (PID $(cat "$PID_FILE"))"
    else
        echo "RADIUS daemon is NOT running"
        return 1
    fi
}

case "${1:-start}" in
    start)   start ;;
    stop)    stop ;;
    restart) stop; sleep 1; start ;;
    status)  status ;;
    *) echo "Usage: bash start_radius.sh {start|stop|restart|status}"; exit 1 ;;
esac

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
    # Record the size of the daemon log BEFORE launching. logs/radius.log is
    # append-only and long-lived, so a "Listening on UDP" line from a previous
    # run would otherwise report instant success for a daemon that never came
    # up. We only ever search the bytes appended past this offset.
    local daemon_log="${SCRIPT_DIR}/logs/radius.log"
    local offset=0
    if [ -f "$daemon_log" ]; then
        offset=$(wc -c < "$daemon_log" | tr -d ' ')
    fi

    nohup "$PHP_BIN" "$RADIUS_SCRIPT" >> "$LOG_FILE" 2>&1 &
    echo $! > "$PID_FILE"

    # Readiness comes from the daemon's own log, not from the PID. The PID only
    # proves a process was forked; under Git Bash on Windows `kill -0 $!` can
    # even succeed after the real process has exited. radius_server.php writes
    # "Listening on UDP" only after it has successfully bound its socket, which
    # is a true happens-after signal that the daemon is actually serving.
    local waited=0
    while [ "$waited" -lt 32 ]; do
        if tail -c +$((offset + 1)) "$daemon_log" 2>/dev/null | grep -q "Listening on UDP"; then
            echo "RADIUS daemon started (PID $(cat "$PID_FILE"))"
            return 0
        fi
        if ! is_running; then
            echo "RADIUS daemon failed to start — the process exited. Startup log:"
            cat "$LOG_FILE" 2>/dev/null
            return 1
        fi
        sleep 0.25
        waited=$((waited + 1))
    done

    echo "RADIUS daemon started (PID $(cat "$PID_FILE")) but never reported listening within 8s."
    echo "--- last 15 lines of ${LOG_FILE}: ---"
    tail -n 15 "$LOG_FILE" 2>/dev/null
    echo "--- last 15 lines of ${daemon_log}: ---"
    tail -n 15 "$daemon_log" 2>/dev/null
    return 1
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

# Silent, idempotent start-if-not-running — for a cron fallback on hosts with
# no systemd access (prefer deploy/mangonet-radius.service on a VPS; this is
# the belt to that unit's braces, or the only option on shared hosting):
#   * * * * * cd /path/to/app && bash start_radius.sh cron >/dev/null 2>&1
# Deliberately quiet on success (cron mails any stdout by default) — only
# prints if it actually had to start the daemon back up.
cron_heal() {
    if is_running; then
        return 0
    fi
    nohup "$PHP_BIN" "$RADIUS_SCRIPT" >> "$LOG_FILE" 2>&1 &
    echo $! > "$PID_FILE"
    echo "$(date '+%Y-%m-%d %H:%M:%S') RADIUS daemon was not running — restarted (PID $!)"
}

case "${1:-start}" in
    start)   start ;;
    stop)    stop ;;
    restart) stop; sleep 1; start ;;
    status)  status ;;
    cron)    cron_heal ;;
    *) echo "Usage: bash start_radius.sh {start|stop|restart|status|cron}"; exit 1 ;;
esac

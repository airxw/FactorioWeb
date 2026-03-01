#!/bin/bash
# 停止自动响应守护进程

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PID_FILE="$SCRIPT_DIR/auto_responder.pid"

if [ ! -f "$PID_FILE" ]; then
    echo "守护进程未运行"
    exit 0
fi

PID=$(cat "$PID_FILE")

if ps -p "$PID" > /dev/null 2>&1; then
    kill "$PID"
    rm -f "$PID_FILE"
    echo "守护进程已停止"
else
    rm -f "$PID_FILE"
    echo "守护进程未运行"
fi

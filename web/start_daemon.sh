#!/bin/bash
# 启动自动响应守护进程

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PID_FILE="$SCRIPT_DIR/auto_responder.pid"
DAEMON_SCRIPT="$SCRIPT_DIR/auto_responder_daemon.php"

if [ -f "$PID_FILE" ]; then
    PID=$(cat "$PID_FILE")
    if ps -p "$PID" > /dev/null 2>&1; then
        echo "守护进程已在运行中 (PID: $PID)"
        exit 0
    fi
fi

nohup php "$DAEMON_SCRIPT" run > /dev/null 2>&1 &
PID=$!

echo $PID > "$PID_FILE"

sleep 1

if ps -p "$PID" > /dev/null 2>&1; then
    echo "守护进程已启动 (PID: $PID)"
else
    echo "启动失败"
    rm -f "$PID_FILE"
    exit 1
fi

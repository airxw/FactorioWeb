#!/bin/bash
# ============================================================================
# 启动自动响应守护进程
# 
# ⚠️⚠️⚠️ 警告 ⚠️⚠️⚠️
# 
# 请勿直接执行此脚本！
# 必须通过 Web 界面进行启动和停止。
# 
# 直接启动可能导致多个守护进程实例同时运行，造成消息重复发送等问题。
# 
# 自动响应系统随服务器启停联动运行，无需手动管理。
# 启动服务器时自动启动，停止服务器时自动停止。
# ============================================================================

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WEB_DIR="$(dirname "$SCRIPT_DIR")"
PID_FILE="$(dirname "$WEB_DIR")/run/autoResponder.pid"
DAEMON_SCRIPT="$WEB_DIR/auto_responder_daemon.php"

if [ -f "$PID_FILE" ]; then
    PID=$(cat "$PID_FILE")
    if ps -p "$PID" > /dev/null 2>&1; then
        echo "守护进程已在运行中 (PID: $PID)"
        exit 0
    fi
fi

# 启动守护进程（使用run模式，现在是守护进程模式）
nohup php "$DAEMON_SCRIPT" run > /dev/null 2>&1 &

# 等待守护进程启动
 sleep 2

if [ -f "$PID_FILE" ]; then
    PID=$(cat "$PID_FILE")
    if ps -p "$PID" > /dev/null 2>&1; then
        echo "守护进程已启动 (PID: $PID)"
    else
        echo "启动失败"
        rm -f "$PID_FILE"
        exit 1
    fi
else
    echo "启动失败: 未创建PID文件"
    exit 1
fi

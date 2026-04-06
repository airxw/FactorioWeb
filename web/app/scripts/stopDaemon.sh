#!/bin/bash
# ============================================================================
# 停止自动响应守护进程
# 
# ⚠️⚠️⚠️ 警告 ⚠️⚠️⚠️
# 
# 请勿直接执行此脚本！
# 必须通过 Web 界面进行启动和停止。
# 
# 直接停止可能导致状态不一致。
# 
# 自动响应系统随服务器启停联动运行，无需手动管理。
# 启动服务器时自动启动，停止服务器时自动停止。
# ============================================================================

# 使用web根目录的PID文件
PID_FILE="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)/run/autoResponder.pid"

if [ ! -f "$PID_FILE" ]; then
    echo "守护进程未运行"
    exit 0
fi

PID=$(cat "$PID_FILE")

if ps -p "$PID" > /dev/null 2>&1; then
    if kill "$PID"; then
        rm -f "$PID_FILE"
        echo "守护进程已停止"
    else
        echo "停止失败：权限不足"
    fi
else
    rm -f "$PID_FILE"
    echo "守护进程未运行"
fi

#!/bin/bash
# Factorio 自动响应系统 - Cron 设置脚本
# 此脚本用于设置 Linux cron 定时任务，让自动响应系统在后台运行

echo "=========================================="
echo "Factorio 自动响应系统 - Cron 设置"
echo "=========================================="

# 获取当前目录
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WEB_DIR="$(dirname "$SCRIPT_DIR")"
PHP_SCRIPT="$WEB_DIR/auto_responder.php"
LOG_FILE="$WEB_DIR/storage/logs/auto_responder.log"

# 检查 PHP 是否安装
if ! command -v php &> /dev/null; then
    echo "错误: 未找到 PHP。请先安装 PHP。"
    echo "安装命令: sudo apt-get install php-cli (Debian/Ubuntu)"
    echo "         sudo yum install php-cli (CentOS/RHEL)"
    exit 1
fi

echo ""
echo "PHP 路径: $(which php)"
echo "脚本路径: $PHP_SCRIPT"
echo "日志路径: $LOG_FILE"
echo ""

# 检查脚本文件是否存在
if [ ! -f "$PHP_SCRIPT" ]; then
    echo "错误: 找不到脚本文件: $PHP_SCRIPT"
    exit 1
fi

# 创建日志文件（如果不存在）
touch "$LOG_FILE"
chmod 644 "$LOG_FILE"

echo "=========================================="
echo "请选择操作:"
echo "=========================================="
echo "1) 安装/更新 Cron 任务（推荐：每分钟执行）"
echo "2) 卸载 Cron 任务"
echo "3) 手动测试运行一次"
echo "4) 查看最近日志"
echo "5) 退出"
echo ""

read -p "请输入选项 (1-5): " choice

case $choice in
    1)
        echo ""
        echo "正在安装 Cron 任务..."
        
        # 获取当前用户的 crontab
        CURRENT_CRON=$(crontab -l 2>/dev/null || true)
        
        # 检查是否已存在相同的任务
        if echo "$CURRENT_CRON" | grep -q "auto_responder.php"; then
            echo "检测到已存在的 Cron 任务，正在更新..."
            # 删除旧的条目
            CURRENT_CRON=$(echo "$CURRENT_CRON" | grep -v "auto_responder.php")
        fi
        
        # 添加新的 cron 任务（每分钟执行一次）
        NEW_CRON="$CURRENT_CRON
# Factorio 自动响应系统 - 每分钟检查一次
* * * * * /usr/bin/php $PHP_SCRIPT >> $LOG_FILE 2>&1"
        
        # 安装新的 crontab
        echo "$NEW_CRON" | crontab -
        
        if [ $? -eq 0 ]; then
            echo ""
            echo "✓ Cron 任务安装成功！"
            echo ""
            echo "任务详情:"
            echo "  - 执行频率: 每分钟"
            echo "  - 执行命令: /usr/bin/php $PHP_SCRIPT"
            echo "  - 日志文件: $LOG_FILE"
            echo ""
            echo "您可以使用 'crontab -l' 查看当前的所有 cron 任务。"
        else
            echo ""
            echo "✗ Cron 任务安装失败，请检查权限。"
        fi
        ;;
        
    2)
        echo ""
        echo "正在卸载 Cron 任务..."
        
        # 获取当前 crontab 并删除相关条目
        CURRENT_CRON=$(crontab -l 2>/dev/null || true)
        NEW_CRON=$(echo "$CURRENT_CRON" | grep -v "auto_responder.php" | grep -v "Factorio 自动响应系统")
        
        # 安装更新后的 crontab
        echo "$NEW_CRON" | crontab -
        
        if [ $? -eq 0 ]; then
            echo ""
            echo "✓ Cron 任务已卸载。"
        else
            echo ""
            echo "✗ 卸载失败，请手动检查 crontab。"
        fi
        ;;
        
    3)
        echo ""
        echo "正在手动运行自动响应脚本..."
        echo "=========================================="
        php "$PHP_SCRIPT"
        echo "=========================================="
        echo ""
        echo "脚本执行完成。"
        echo "请检查日志文件了解详情: $LOG_FILE"
        ;;
        
    4)
        echo ""
        echo "最近的日志内容:"
        echo "=========================================="
        if [ -f "$LOG_FILE" ]; then
            tail -n 50 "$LOG_FILE"
        else
            echo "日志文件不存在。"
        fi
        echo "=========================================="
        ;;
        
    5)
        echo ""
        echo "退出设置程序。"
        exit 0
        ;;
        
    *)
        echo ""
        echo "无效选项，退出。"
        exit 1
        ;;
esac

echo ""
echo "按 Enter 键退出..."
read

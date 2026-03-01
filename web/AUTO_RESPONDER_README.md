# Factorio 服务器自动响应系统

## 功能概述

这个自动响应系统可以在服务端后台自动执行以下功能，无需保持浏览器打开：

### 1. 周期性消息
- 按照设定的时间间隔自动发送消息（如每小时发送服务器公告）
- 支持：15分钟、30分钟、每小时、每2小时、每6小时、每12小时、每天

### 2. 定时任务
- 在指定的时间点发送一次性消息
- 适用于活动预告、维护通知等

### 3. 关键词自动回复
- 监听游戏内聊天内容
- 当玩家发送包含特定关键词的消息时，自动回复预设内容
- 支持变量：`{player}` 表示发送消息的玩家名

### 4. 玩家事件响应
- **上线欢迎**：玩家加入游戏时自动发送欢迎消息
- **下线告别**：玩家离开游戏时自动发送告别消息
- 支持变量：`{player}` 表示玩家名

## 安装步骤

### 第一步：设置 Cron 任务

在 Linux 服务器上，需要设置 cron 任务来定期执行自动响应脚本。

**方法一：使用自动设置脚本（推荐）**

```bash
cd /www/wwwroot/factorio/web
./setup_cron.sh
```

然后选择选项 1 安装 Cron 任务。

**方法二：手动设置**

```bash
# 编辑当前用户的 crontab
crontab -e

# 添加以下行（每分钟执行一次）
* * * * * /usr/bin/php /www/wwwroot/factorio/web/auto_responder.php >> /www/wwwroot/factorio/web/auto_responder.log 2>&1
```

### 第二步：验证安装

1. 运行测试命令：
```bash
php /www/wwwroot/factorio/web/auto_responder.php
```

2. 查看日志：
```bash
tail -f /www/wwwroot/factorio/web/auto_responder.log
```

## 使用方法

### 通过 Web 界面配置

1. 打开 Factorio 服务器管理页面
2. 在聊天区域点击"聊天设置"按钮
3. 在弹出的设置面板中配置各项功能：
   - **周期性消息**：设置自动重复发送的公告
   - **定时任务**：设置一次性定时消息
   - **关键词回复**：设置自动回复规则
   - **玩家事件**：设置上线欢迎和下线告别消息

### 配置示例

#### 周期性消息示例
- 消息内容：`欢迎游玩本服务器！请遵守游戏规则。`
- 间隔：每小时

#### 关键词回复示例
- 关键词：`帮助`
- 响应内容：`{player}，请查看服务器公告或联系管理员获取帮助。`

#### 玩家事件示例
- 上线欢迎：`欢迎 {player} 加入游戏！祝你游戏愉快！`
- 下线告别：` {player} 离开了游戏，再见！`

## 文件说明

| 文件 | 说明 |
|------|------|
| `auto_responder.php` | 自动响应系统主程序 |
| `auto_responder_state.json` | 运行状态记录（自动创建） |
| `auto_responder.log` | 运行日志（自动创建） |
| `chat_settings.json` | 配置文件存储 |
| `chat_settings.php` | 配置管理 API |
| `setup_cron.sh` | Cron 任务设置脚本 |

## 注意事项

1. **服务器必须运行**：自动响应系统只在 Factorio 服务器运行时生效
2. **日志文件权限**：确保 PHP 有权限写入日志文件和状态文件
3. **时间设置**：定时任务使用服务器本地时间
4. **性能影响**：系统每分钟检查一次，对服务器性能影响极小

## 故障排除

### 自动响应不工作

1. 检查 cron 任务是否正确设置：
   ```bash
   crontab -l
   ```

2. 检查日志文件：
   ```bash
   tail /www/wwwroot/factorio/web/auto_responder.log
   ```

3. 手动运行测试：
   ```bash
   php /www/wwwroot/factorio/web/auto_responder.php
   ```

### 关键词回复不触发

1. 确保关键词匹配（支持部分匹配）
2. 检查日志中是否有聊天消息被正确解析
3. 确认 `factorio-current.log` 文件路径正确

### 玩家事件不触发

1. 确认玩家事件功能已启用
2. 检查日志中是否有 `[JOIN]` 和 `[LEAVE]` 标记
3. 确认消息内容不为空

## 技术支持

如有问题，请检查：
1. PHP 是否正确安装：`php -v`
2. Screen 会话是否存在：`screen -ls | grep factorio_server`
3. 文件权限是否正确：`ls -la /www/wwwroot/factorio/web/`

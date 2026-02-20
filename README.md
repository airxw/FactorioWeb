# FactorioWeb

FactorioWeb 是一个专为 Factorio 游戏服务器设计的 Web 管理界面，提供了直观、便捷的服务器管理工具。

## 项目功能

- **服务器配置管理**：通过 Web 界面编辑服务器设置、地图生成设置等配置文件
- **地图管理**：查看和管理服务器地图
- **监控功能**：实时监控服务器状态和日志
- **用户认证**：安全的登录系统
- **WebSocket 支持**：通过 WebSocket 实现实时通信

## 技术栈

- **后端**：PHP + Workerman (WebSocket 服务器)
- **前端**：HTML + JavaScript + Axios + Bootstrap Icons
- **配置文件**：JSON 格式

## 目录结构

```
FactorioWeb/
├── data/                  # 配置文件示例目录
│   ├── map-gen-settings.example.json
│   ├── map-settings.example.json
│   ├── server-settings.example.json
│   └── server-whitelist.example.json
├── server/                # 服务器配置目录
│   ├── configs/           # 服务器配置文件
│   ├── server-adminlist.json
│   └── server-banlist.json
├── setting/               # 实际使用的配置文件目录
│   ├── map-gen-settings.example.json
│   ├── map-settings.example.json
│   ├── server-settings.example.json
│   ├── server-settings.json
│   └── server-whitelist.example.json
├── web/                   # Web 界面目录
│   ├── Workerman/         # Workerman WebSocket 库
│   ├── static/            # 静态资源
│   ├── api.php            # API 接口
│   ├── auth.php           # 认证系统
│   ├── config-editor.php  # 配置编辑器
│   ├── index.html         # 主页
│   └── ...                # 其他 Web 界面文件
└── README.md              # 项目说明
```

## 安装方法

### 1. 克隆仓库

```bash
git clone https://github.com/airxw/FactorioWeb.git
cd FactorioWeb
```

### 2. 安装依赖

项目使用 PHP 和 Workerman，需要确保服务器上安装了 PHP 7.4 或更高版本。

```bash
# 安装 Workerman 依赖
cd web/Workerman
composer install
```

### 3. 配置服务器
这个可以不用做，直接在web中进行设置即可。

1. 复制配置文件示例并根据需要修改：

```bash
# 复制服务器设置文件
cp setting/server-settings.example.json setting/server-settings.json

# 复制地图生成设置文件
cp setting/map-gen-settings.example.json setting/map-gen-settings.json

# 复制地图设置文件
cp setting/map-settings.example.json setting/map-settings.json
```

2. 编辑配置文件，根据你的服务器环境进行调整。

### 4. 启动 Web 服务器
这个也可以不用做，直接在web中进行设置即可。
项目使用 Workerman 作为 WebSocket 服务器，你可以通过以下命令启动：

```bash
# 启动 WebSocket 服务器
cd web
php websocket_server.php start
```

### 5. 配置 Web 服务器
建议新手使用宝塔直接梭哈，配置好后直接在宝塔中启动项目即可。如果使用宝塔梭哈，请不用看以下内容。

你需要配置一个 Web 服务器（如 Apache 或 Nginx）来托管 Web 界面。以下是 Nginx 配置示例：

```nginx
server {
    listen 80;
    server_name factorio-web.example.com;
    root /path/to/FactorioWeb/web;
    index index.html index.php;

    location / {
        try_files $uri $uri/ /index.html;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php7.4-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

## 使用说明

### 1. 访问 Web 界面

在浏览器中访问你的服务器地址，例如 `http://factorio-web.example.com`。

### 2. 登录系统

首次访问时，你需要登录系统。默认登录凭据可能需要在 `auth.php` 文件中配置。

### 3. 管理服务器

登录后，你可以：

- **查看服务器状态**：在监控页面查看服务器的实时状态
- **编辑配置文件**：在配置编辑器中修改服务器设置
- **管理地图**：在地图页面查看和管理服务器地图
- **查看日志**：通过 WebSocket 实时查看服务器日志

### 4. 配置 WebSocket 连接

确保 WebSocket 服务器正在运行，并且前端配置正确指向 WebSocket 服务器的地址。你可能需要在 `web/static/js/app.bundle.js` 中修改 WebSocket 连接地址。

## 安全注意事项

1. **修改默认登录凭据**：确保修改默认的登录凭据，使用强密码
2. **限制访问**：考虑使用防火墙或 IP 白名单限制对 Web 界面的访问
3. **定期更新**：定期更新项目代码和依赖，以修复安全漏洞
4. **使用 HTTPS**：在生产环境中使用 HTTPS 加密传输

## 故障排除

### WebSocket 连接失败

1. 确保 Workerman WebSocket 服务器正在运行
2. 检查防火墙设置，确保 WebSocket 端口（默认为 8080）已开放（内网转发，其实这个也无所谓）
3. 检查前端配置中的 WebSocket 连接地址是否正确

### 配置文件编辑后不生效

1. 确保保存了配置文件
2. 重启 Factorio 服务器以应用新的配置
3. 检查配置文件格式是否正确（JSON 格式）

### 项目效果
https://github.com/airxw/FactorioWeb/blob/main/image.png
默认用户admin
默认密码password
## 贡献

欢迎提交 Issue 和 Pull Request 来改进这个项目。

## 许可证

本项目使用 MIT 许可证。

## 联系方式

- GitHub: [https://github.com/airxw/FactorioWeb](https://github.com/airxw/FactorioWeb)
![alt text](image.png)

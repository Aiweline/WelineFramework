# WLS Gateway - 多项目统一入口反向代理

## 概述

WLS Gateway 是一个基于 SNI（Server Name Indication）的 TCP 反向代理，允许多个项目共享同一个端口（如 443）。

## 架构

```
客户端请求
  ↓
WLS Gateway (0.0.0.0:443)
  ↓ 根据 SNI 域名路由
  ├─→ 项目A (127.0.0.1:10443) - project-a.com
  ├─→ 项目B (127.0.0.1:10444) - project-b.com
  └─→ 项目C (127.0.0.1:10445) - *.dev.local
```

## 使用场景

### 1. 开发环境 - 多项目共享 443 端口

**问题**：多个项目都想监听 443 端口，但端口只能被一个进程占用。

**解决方案**：
- Gateway 监听 443
- 各项目监听内网高端口（10443, 10444, ...）
- Gateway 根据域名转发

### 2. 生产环境 - 单 IP 多域名部署

**问题**：服务器只有一个公网 IP，但需要部署多个项目。

**解决方案**：
- 配置 DNS：project-a.com → 服务器 IP
- 配置 DNS：project-b.com → 服务器 IP
- Gateway 根据域名分发到不同项目

## 配置方式

### 方式一：自动发现（推荐）

Gateway 会自动扫描 `var/server/instances/` 目录，发现所有运行中的项目实例。

```bash
# 启动 Gateway（自动发现模式）
php bin/w gateway:start
```

### 方式二：env.php 配置

在 `app/etc/env.php` 中添加：

```php
return [
    // ... 其他配置

    'wls' => [
        'gateway' => [
            'listen' => '0.0.0.0:443',
            'routes' => [
                // 精确匹配
                'project-a.com' => [
                    'host' => '127.0.0.1',
                    'port' => 10443,
                    'ssl' => true,
                ],
                'project-b.com' => [
                    'host' => '127.0.0.1',
                    'port' => 10444,
                    'ssl' => true,
                ],
                // 通配符匹配
                '*.dev.local' => [
                    'host' => '127.0.0.1',
                    'port' => 10445,
                    'ssl' => true,
                ],
            ],
            // 默认后端（当域名不匹配时）
            'default' => [
                'host' => '127.0.0.1',
                'port' => 10443,
                'ssl' => true,
            ],
        ],
    ],
];
```

### 方式三：独立配置文件

创建 `app/etc/gateway.php`：

```php
return [
    'listen' => '0.0.0.0:443',
    'routes' => [
        'api.example.com' => ['host' => '127.0.0.1', 'port' => 8443],
        'admin.example.com' => ['host' => '127.0.0.1', 'port' => 9443],
    ],
    'default' => ['host' => '127.0.0.1', 'port' => 8443],
];
```

启动：

```bash
php bin/w gateway:start --config=app/etc/gateway.php
```

## 部署步骤

### 开发环境

1. **启动各项目（使用不同端口）**

```bash
# 项目 A
cd /path/to/project-a
php bin/w server:start -p 10443

# 项目 B
cd /path/to/project-b
php bin/w server:start -p 10444
```

2. **配置 hosts 文件**

```
127.0.0.1 project-a.local
127.0.0.1 project-b.local
```

3. **启动 Gateway**

```bash
cd /path/to/project-a  # 任意项目目录
php bin/w gateway:start
```

4. **访问**

- https://project-a.local/
- https://project-b.local/

### 生产环境

1. **配置 DNS**

```
project-a.com  A  服务器IP
project-b.com  A  服务器IP
```

2. **启动各项目**

```bash
# 项目 A
php bin/w server:start -p 10443 --host 127.0.0.1

# 项目 B
php bin/w server:start -p 10444 --host 127.0.0.1
```

3. **配置 Gateway**

在 `app/etc/env.php` 中配置路由规则（见上文）。

4. **启动 Gateway**

```bash
php bin/w gateway:start
```

5. **配置防火墙**

```bash
# 开放 443 端口
firewall-cmd --add-port=443/tcp --permanent
firewall-cmd --reload
```

## 优势

### vs Nginx

| 特性 | WLS Gateway | Nginx |
|------|-------------|-------|
| 配置复杂度 | 自动发现，零配置 | 需要手动配置 |
| 动态路由 | 支持（自动发现新项目） | 需要重启 |
| 性能 | 纯 TCP 转发，极低开销 | HTTP 解析，开销较大 |
| 部署 | 单个 PHP 进程 | 需要额外安装 |

### vs HAProxy

| 特性 | WLS Gateway | HAProxy |
|------|-------------|---------|
| 学习曲线 | 简单 | 复杂 |
| 集成度 | 原生集成 WLS | 需要额外配置 |
| 健康检查 | 自动（基于实例文件） | 需要配置 |

## 注意事项

1. **端口冲突**：Gateway 监听 443 端口，各项目不能再监听 443
2. **SSL 证书**：各项目需要配置自己的 SSL 证书
3. **性能**：Gateway 是单进程，适合中小规模部署（< 1000 并发）
4. **高可用**：生产环境建议使用 Supervisor 或 systemd 管理 Gateway 进程

## 故障排查

### Gateway 无法启动

```bash
# 检查 443 端口是否被占用
netstat -ano | grep :443

# 检查权限（Linux 需要 root 权限监听 443）
sudo php bin/w gateway:start
```

### 域名无法路由

```bash
# 检查路由规则
php bin/w gateway:start --config=app/etc/gateway.php

# 查看日志
tail -f var/log/wls/gateway.log
```

### SSL 握手失败

- 检查各项目的 SSL 证书是否正确
- 确保证书的 CN（Common Name）与域名匹配

## 未来增强

- [ ] 支持 HTTP/2
- [ ] 支持 WebSocket
- [ ] 健康检查和自动故障转移
- [ ] 负载均衡（同一域名多个后端）
- [ ] 访问日志和统计
- [ ] 热重载配置

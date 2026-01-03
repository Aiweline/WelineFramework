# Weline_Async 自动同步模块

## 简介

Weline_Async 是一个自动同步模块，支持将本地文件自动同步到远程服务器。当本地文件发生变化时，会自动通过 rsync 同步到远程服务器。

## 功能特性

- ✅ 多主机支持：可以配置多个远程服务器
- ✅ 目录映射：每个主机可以配置多个本地到远程的目录映射
- ✅ **项目配置文件**：支持在项目根目录配置 `weline-async.json`，作为默认项目库同步监控
- ✅ 实时监控：使用 Node.js chokidar 实时监控文件变化
- ✅ 自动同步：文件变化时自动通过 rsync 同步
- ✅ 后台管理：完整的后台管理界面
- ✅ 守护进程：支持守护进程模式，自动重启
- ✅ 跨平台：支持 Windows 和 Linux
- ✅ phar独立包：支持打包为独立运行的 phar 包

## 安装

### 1. 安装模块

模块已包含在项目中，运行以下命令安装：

```bash
php bin/w setup:upgrade
```

### 2. 安装 Node.js 依赖

进入模块的 bin 目录，安装依赖：

```bash
cd app/code/Weline/Async/bin
npm install
```

## 使用指南

### 0. 项目配置文件（推荐）

除了后台配置，还可以在项目根目录创建 `weline-async.json` 文件作为默认的项目库同步监控配置。

**配置文件位置**：项目根目录（BP）/ `weline-async.json`

**配置文件格式**：

```json
{
  "host": {
    "host": "192.168.1.100",
    "port": 22,
    "user": "root",
    "password": "your_password",
    "key_path": "/path/to/id_rsa"
  },
  "mapping": {
    "local_path": "/path/to/local/directory",
    "remote_path": "/path/to/remote/directory",
    "exclude_patterns": ["node_modules", ".git", "*.log"]
  }
}
```

**特性**：
- 只要 `weline-async.json` 存在，就会自动运行
- 与后台配置互不影响，可以同时运行
- 主要用于项目级别的默认同步

**详细说明**：请参考 [项目配置文件使用指南](./项目配置文件使用指南.md)

### 1. 配置主机

1. 登录后台管理系统
2. 进入"系统服务" > "文件同步服务" > "同步主机管理"
3. 点击"新增主机"
4. 填写主机信息：
   - 主机名称
   - 主机地址（IP 或域名）
   - SSH 端口（默认 22）
   - SSH 用户名
   - SSH 密码或密钥路径
   - 描述（可选）

### 2. 配置目录映射

1. 在主机列表中，点击"同步映射"
2. 点击"新增映射"
3. 填写映射信息：
   - 本地路径（本地目录的完整路径）
   - 远程路径（远程服务器上的目录路径）
   - 排除模式（可选，每行一个，支持通配符）
   - 状态（开启/关闭）

### 3. 开启同步

1. 在映射列表中，点击"开启"按钮
2. 系统会自动启动 watcher 监控该映射
3. 本地文件变化时会自动同步到远程服务器

### 4. 查看状态

在映射列表中可以看到：
- 状态：开启/关闭
- 运行状态：运行中/未运行
- PID：进程ID（如果正在运行）

## 项目配置文件

除了后台配置，还可以在项目根目录创建 `weline-async.json` 文件作为默认的项目库同步监控配置。

**重要特性**：
- ✅ 只要 `weline-async.json` 存在，就会**自动运行**
- ✅ 与后台配置**互不影响**，可以同时运行
- ✅ 主要用于**项目级别的默认同步**（如开发环境同步到测试环境）
- ✅ 配置文件已添加到 `.gitignore`，不会被提交到版本控制

**配置文件位置**：项目根目录（BP）/ `weline-async.json`

**配置文件格式**：

```json
{
  "host": {
    "host": "192.168.1.100",
    "port": 22,
    "user": "root",
    "password": "your_password",
    "key_path": "/path/to/id_rsa"
  },
  "mapping": {
    "local_path": "/path/to/local/directory",
    "remote_path": "/path/to/remote/directory",
    "exclude_patterns": ["node_modules", ".git", "*.log"]
  }
}
```

**详细说明**：请参考 [项目配置文件使用指南](./项目配置文件使用指南.md)

**示例文件**：`app/code/Weline/Async/weline-async.json.example`

## 命令行使用

### 启动 watcher

```bash
# 启动所有开启的映射（包括项目配置）
php bin/w async:start

# 启动指定主机的所有映射
php bin/w async:start --host=1

# 启动指定映射
php bin/w async:start --mapping=1
```

### 停止 watcher

```bash
# 停止所有watcher
php bin/w async:stop

# 停止指定主机的watcher
php bin/w async:stop --host=1

# 停止指定映射的watcher
php bin/w async:stop --mapping=1
```

### 重启 watcher

```bash
# 重启所有watcher
php bin/w async:restart

# 重启指定主机的watcher
php bin/w async:restart --host=1

# 重启指定映射的watcher
php bin/w async:restart --mapping=1
```

### 查看状态

```bash
php bin/w async:status
```

状态输出会显示：
- 后台配置的映射（`mapping_id` 为数字）
- 项目配置的映射（`mapping_id` 为 `project`，`type` 为 `project`）

### 守护进程模式

```bash
# 启动守护进程（默认检查间隔60秒）
php bin/w async:daemon

# 指定检查间隔
php bin/w async:daemon --interval=30
```

守护进程会自动监控所有开启的映射，如果发现某个映射未运行，会自动重启。

## phar 独立包

### 生成 phar 包

```bash
php bin/w async:build:phar
```

生成的 phar 包位于：`var/async/sync.phar`

### 使用 phar 包

```bash
# 查看帮助
php sync.phar -h

# 启动watcher
php sync.phar start

# 查看状态
php sync.phar status

# 守护进程模式
php sync.phar daemon
```

## 系统服务配置

### Linux systemd

1. 复制服务配置文件：
```bash
sudo cp app/code/Weline/Async/etc/systemd/sync.service /etc/systemd/system/
```

2. 编辑配置文件，修改路径：
```bash
sudo nano /etc/systemd/system/sync.service
```

3. 启动服务：
```bash
sudo systemctl start sync
sudo systemctl enable sync
```

### Windows 服务

参考 `doc/windows-service.md` 文件。

## 日志

日志文件位于：`var/async/logs/mapping_{mapping_id}.log`

每个映射有独立的日志文件，记录文件变化和同步操作。

## 故障排除

### watcher 无法启动

1. 检查 Node.js 是否安装：`node --version`
2. 检查依赖是否安装：`cd app/code/Weline/Async/bin && npm install`
3. 检查配置文件是否正确
4. 查看日志文件

### 同步失败

1. 检查 SSH 连接是否正常
2. 检查远程路径是否存在
3. 检查权限是否正确
4. 查看日志文件获取详细错误信息

### 文件未同步

1. 检查映射状态是否为"开启"
2. 检查 watcher 是否正在运行
3. 检查排除模式是否匹配了该文件
4. 查看日志文件

## 技术支持

- 官网：https://bbs.aiweline.com
- 邮箱：aiweline@qq.com

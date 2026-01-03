# phar 独立包使用指南

## 简介

phar 独立包是一个可以独立运行的同步工具，不依赖 WelineFramework，可以复制到任何目录直接使用。

## 生成 phar 包

### 方法一：使用模块命令

```bash
php bin/w async:build:phar
```

生成的 phar 包位于：`var/async/sync.phar`

### 方法二：使用独立打包脚本

```bash
cd app/code/Weline/Async
php phar/build.php
```

## 使用 phar 包

### 复制到目标目录

```bash
cp var/async/sync.phar /path/to/target/
```

### 设置可执行权限（Linux/Mac）

```bash
chmod +x sync.phar
```

### 查看帮助

```bash
php sync.phar -h
# 或
./sync.phar -h
```

### 基本命令

```bash
# 启动watcher
php sync.phar start

# 停止watcher
php sync.phar stop

# 重启watcher
php sync.phar restart

# 查看状态
php sync.phar status

# 守护进程模式
php sync.phar daemon
```

## 配置

phar 独立包需要配置文件来连接数据库和读取配置。

配置文件位置：`config.json`（与 sync.phar 同目录）

配置文件格式：

```json
{
  "database": {
    "host": "localhost",
    "port": 3306,
    "dbname": "weline",
    "username": "root",
    "password": "password"
  }
}
```

## 注意事项

1. phar 包需要 PHP 5.6+ 支持
2. 需要安装 Node.js 和 rsync
3. 需要配置数据库连接
4. 需要确保有执行权限

## 限制

phar 独立包相比模块版本有以下限制：

1. 不包含完整的后台管理界面
2. 需要手动配置数据库连接
3. 部分高级功能可能不可用

建议在生产环境中使用模块版本，phar 包主要用于简单的同步场景。

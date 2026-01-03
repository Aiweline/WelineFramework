# Windows 服务配置指南

## 使用 NSSM 配置 Windows 服务

NSSM (Non-Sucking Service Manager) 是一个 Windows 服务管理工具，可以将任何程序注册为 Windows 服务。

### 1. 下载 NSSM

从 [NSSM 官网](https://nssm.cc/download) 下载最新版本。

### 2. 安装服务

打开命令提示符（以管理员身份运行），执行：

```cmd
nssm install WelineAsyncSync
```

### 3. 配置服务

在弹出的配置窗口中设置：

- **Path**: `C:\php\php.exe` (PHP 可执行文件路径)
- **Startup directory**: `C:\path\to\weline` (Weline 项目根目录)
- **Arguments**: `bin\w async:daemon`

### 4. 启动服务

```cmd
nssm start WelineAsyncSync
```

### 5. 其他命令

```cmd
# 停止服务
nssm stop WelineAsyncSync

# 重启服务
nssm restart WelineAsyncSync

# 删除服务
nssm remove WelineAsyncSync
```

## 使用 Windows Task Scheduler

也可以使用 Windows 任务计划程序来运行守护进程。

### 1. 创建基本任务

1. 打开"任务计划程序"
2. 创建基本任务
3. 设置触发器为"系统启动时"
4. 操作设置为启动程序：`php.exe`
5. 参数：`bin\w async:daemon`
6. 起始于：Weline 项目根目录

### 2. 配置任务

- 勾选"使用最高权限运行"
- 设置"如果任务失败，重新启动任务"
- 设置重新启动间隔为 1 分钟

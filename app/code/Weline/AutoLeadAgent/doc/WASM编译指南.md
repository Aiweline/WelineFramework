# WASM编译指南

## 概述

本文档说明如何编译AutoLeadAgent的WASM核心算法模块。

编译使用 **WASI SDK** - 一个预编译的便携工具链，无需额外依赖（如Python），直接下载解压即可使用。

## 快速开始

### 使用命令行工具（推荐）

```bash
# 编译（自动下载并安装 WASI SDK）
php bin/m wasm:compile

# 查看编译环境
php bin/m wasm:compile --env

# 强制重新编译
php bin/m wasm:compile --force
```

## 目录结构

```
app/code/Weline/AutoLeadAgent/wasm/
├── src/              # 源码目录
│   ├── agent_core.cpp
│   ├── agent_core.h
│   ├── binding.cpp
│   └── CMakeLists.txt
├── deps/             # 依赖目录（自动安装，已忽略Git）
│   └── wasi-sdk/     # WASI SDK 工具链
└── output/           # 输出目录
    └── agent-core.wasm
```

## WASI SDK

### 自动安装（推荐）

运行 `php bin/m wasm:compile` 会自动下载安装 WASI SDK（约50MB）。

支持的平台：
- Windows x64
- Linux x64
- macOS Intel
- macOS ARM (Apple Silicon)

### 手动安装

如果自动下载失败，可以手动安装：

**1. 下载 WASI SDK**

访问 [WASI SDK Releases](https://github.com/WebAssembly/wasi-sdk/releases) 下载对应平台的压缩包：

| 平台 | 下载链接 |
|------|----------|
| Windows x64 | `wasi-sdk-24.0-x86_64-windows.tar.gz` |
| Linux x64 | `wasi-sdk-24.0-x86_64-linux.tar.gz` |
| macOS Intel | `wasi-sdk-24.0-x86_64-macos.tar.gz` |
| macOS ARM | `wasi-sdk-24.0-arm64-macos.tar.gz` |

**2. 解压到指定目录**

```bash
# 进入依赖目录
cd app/code/Weline/AutoLeadAgent/wasm/deps/

# 解压
tar -xzf wasi-sdk-24.0-*.tar.gz

# 重命名为 wasi-sdk
mv wasi-sdk-24.0 wasi-sdk
```

Windows PowerShell：
```powershell
cd app\code\Weline\AutoLeadAgent\wasm\deps\
tar -xzf wasi-sdk-24.0-x86_64-windows.tar.gz
Rename-Item wasi-sdk-24.0 wasi-sdk
```

**3. 验证安装**

```bash
php bin/m wasm:compile --env
```

应显示 `✓ WASI SDK 已安装`。

**4. 运行编译**

```bash
php bin/m wasm:compile
```

## 命令参数

| 参数 | 说明 |
|------|------|
| `-f, --force` | 强制重新编译，即使 WASM 文件已是最新 |
| `-n, --no-install` | 跳过自动安装 WASI SDK |
| `-i, --install-deps` | 仅安装 WASI SDK，不执行编译 |
| `-c, --clean` | 清理构建目录 |
| `-e, --env` | 显示编译环境信息 |
| `-d, --debug` | 显示调试信息 |

### 使用示例

```bash
# 查看环境
php bin/m wasm:compile --env

# 仅安装 WASI SDK
php bin/m wasm:compile --install-deps

# 跳过自动安装，手动管理依赖
php bin/m wasm:compile --no-install

# 清理后强制重新编译
php bin/m wasm:compile --clean --force
```

## 手动编译

如果不使用命令行工具，可以手动编译：

```bash
cd wasm/src

# 使用 WASI SDK 的 clang 编译
/path/to/wasi-sdk/bin/clang \
    --target=wasm32 -O2 -nostdlib \
    -Wl,--no-entry -Wl,--export-all \
    -o ../output/agent-core.wasm \
    agent_core.cpp
```

## 验证编译

### 检查文件

```bash
# Linux/macOS
ls -lh wasm/output/agent-core.wasm

# Windows PowerShell
Get-Item wasm\output\agent-core.wasm | Select-Object Name, Length
```

### 验证哈希

```bash
# Linux/macOS
sha256sum wasm/output/agent-core.wasm

# Windows PowerShell
Get-FileHash wasm\output\agent-core.wasm -Algorithm SHA256

# PHP
php -r "echo hash_file('sha256', 'wasm/output/agent-core.wasm') . PHP_EOL;"
```

## 常见问题

### 1. 下载失败

**问题**: WASI SDK 下载超时或失败

**解决**:
- 手动下载并解压到 `wasm/deps/wasi-sdk/` 目录
- 使用代理或镜像源
- 检查网络连接

### 2. 解压失败

**问题**: tar 命令无法解压

**解决**:
- Windows 10+ 内置 tar 命令，如果不可用可安装 7-Zip
- 确保有足够的磁盘空间

### 3. 编译失败

**问题**: clang 编译失败

**解决**:
- 检查源码语法
- 查看错误日志
- 确保 WASI SDK 正确安装

### 4. WASM文件过大

**问题**: 编译后的WASM文件太大

**解决**:
- 使用优化级别 `-Oz`（最小体积）
- 移除未使用的代码

### 5. 浏览器不支持

**问题**: 浏览器无法加载WASM

**解决**:
- 检查浏览器版本（需要支持WebAssembly）
- 检查MIME类型配置（application/wasm）
- 检查CORS设置

## 源码说明

### 核心函数

| 函数 | 说明 |
|------|------|
| `calculateCustomerScore` | 计算客户评分 |
| `extractProfileFeatures` | 提取特征向量 |
| `matchProfile` | 匹配客户画像 |
| `cleanData` | 数据清洗 |

### 修改算法

如需修改算法：

1. 编辑 `wasm/src/agent_core.cpp`
2. 运行 `php bin/m wasm:compile --force`
3. 验证编译结果
4. 哈希会自动注册到数据库

## 相关链接

- [WASI SDK Releases](https://github.com/WebAssembly/wasi-sdk/releases)
- [WebAssembly 规范](https://webassembly.org/)

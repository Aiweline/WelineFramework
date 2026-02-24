---
name: php-extension-dependency
description: |
  PHP 扩展检测与自动安装。框架内置 env:check 和 env:install 命令，支持跨平台自动安装缺失扩展。
  涉及扩展、依赖、环境检查时必须命中本技能！

  MUST use when:
  - 检测或安装 PHP 扩展（sockets, event, opcache, pcntl, posix, openssl 等）
  - 模块声明依赖/扩展需求（requirements.php）
  - 环境检查、环境配置、环境问题排查
  - 用户报告 extension_loaded 失败、扩展缺失、函数被禁用
  - 新模块需要特定 PHP 扩展支持
  - disable_functions 导致功能不可用

  Keywords: 扩展, extension, 依赖, dependency, 环境, environment, 环境检查, env:check, env:install,
  extension_loaded, 安装扩展, install extension, 缺少扩展, missing extension, php.ini,
  sockets, event, opcache, pcntl, posix, openssl, curl, mbstring, pdo, fileinfo,
  disable_functions, 函数被禁用, disabled function, pecl, apt-get, docker-php-ext-install,
  requirements.php, 模块依赖, module dependency, 扩展检测, 自动安装, auto install,
  DLL, php_sockets.dll, zend_extension, composer ext-
globs:
  - "**/env/requirements.php"
  - "**/env/script/*.php"
  - "**/Env/**/*.php"
  - "**/Installer/**/*.php"
alwaysApply: false
---

# PHP 扩展检测与自动安装

## 核心命令

### 检查环境

```bash
# 检查所有模块的扩展和函数依赖
php bin/w env:check

# JSON 格式输出（脚本集成用）
php bin/w env:check --json
```

### 自动安装缺失扩展

```bash
# 交互式安装（逐个确认）
php bin/w env:install

# 跳过确认，自动安装所有
php bin/w env:install -y
```

**自动安装策略（按平台）：**

| 平台 | 策略 |
|------|------|
| Windows | 检查 `ext/` 目录是否有 DLL → 修改 php.ini 启用 |
| Linux (Docker) | `docker-php-ext-install` |
| Linux (Ubuntu/Debian) | `phpenmod` → `apt-get install php{ver}-{ext}` |
| Linux (CentOS/RHEL) | `yum/dnf install php-{ext}` |
| 通用回退 | `pecl install {ext}` → 手动启用 php.ini |

## 模块声明扩展依赖

### requirements.php 文件格式

每个模块可在 `env/requirements.php` 中声明依赖。框架自动收集所有模块的需求。

**文件位置**：`app/code/{Vendor}/{Module}/env/requirements.php`

```php
<?php
// app/code/Weline/Server/env/requirements.php
return [
    // PHP 版本要求
    'php' => '^8.1',

    // 必须的扩展（env:check 会标红缺失项）
    'extensions' => ['sockets', 'openssl', 'curl', 'mbstring'],

    // 必须的函数（检查 disable_functions）
    'functions' => ['proc_open', 'exec'],

    // 需要自定义安装脚本的复杂依赖
    'items' => [
        [
            'name' => 'Event Extension',
            'description' => 'High-performance event loop for WLS',
            'check' => "extension_loaded('event')",
            'script_linux' => 'script/install_event_extension.php',
            'script_windows' => 'script/install_event_extension.php',
        ],
    ],

    // 推荐但非必须的扩展（env:check 会标黄）
    'recommended_extensions' => ['event', 'opcache'],

    // 推荐项（带自定义检查脚本）
    'recommended_items' => [
        [
            'name' => 'OPcache',
            'description' => 'PHP bytecode cache for performance',
            'check' => "extension_loaded('Zend OPcache')",
            'script_linux' => 'script/check_opcache.php',
            'script_windows' => 'script/check_opcache.php',
        ],
    ],
];
```

### 创建自定义安装脚本

安装脚本放在 `env/script/` 目录：

**文件位置**：`app/code/{Vendor}/{Module}/env/script/install_{ext}.php`

```php
<?php
// env/script/install_event_extension.php
// 参数：$argv[1] = 'check' | 'install'

$action = $argv[1] ?? 'check';

if ($action === 'check') {
    // 检测逻辑
    if (extension_loaded('event')) {
        echo "INSTALLED\n";
        exit(0);
    }
    echo "MISSING\n";
    exit(1);
}

if ($action === 'install') {
    // 安装逻辑（按平台区分）
    if (PHP_OS_FAMILY === 'Windows') {
        // Windows：检查 DLL 并修改 php.ini
        $extDir = ini_get('extension_dir');
        $dll = $extDir . DIRECTORY_SEPARATOR . 'php_event.dll';
        if (file_exists($dll)) {
            // 修改 php.ini 启用
            $ini = php_ini_loaded_file();
            $content = file_get_contents($ini);
            $content .= "\nextension=event\n";
            file_put_contents($ini, $content);
            echo "ENABLED\n";
            exit(0);
        }
        echo "DLL not found: $dll\n";
        exit(1);
    } else {
        // Linux/macOS：尝试 pecl
        passthru('pecl install event', $code);
        exit($code);
    }
}
```

## 代码中检测扩展

### 检测扩展是否加载

```php
// 单个扩展检测
if (\extension_loaded('sockets')) {
    // sockets 扩展可用
}

// 函数是否可用（考虑 disable_functions）
if (\function_exists('proc_open')) {
    $disabled = \array_map('trim', \explode(',', \ini_get('disable_functions') ?: ''));
    if (!\in_array('proc_open', $disabled, true)) {
        // proc_open 可用且未被禁用
    }
}
```

### 在服务中检测并提示

```php
// 参考 CliServerService 的模式
if (!\extension_loaded('sockets')) {
    // 提示用户安装
    $this->printer->error(__('缺少 sockets 扩展'));
    $this->printer->note(__('运行 php bin/w env:install 自动安装'));
    return;
}
```

## 常见扩展与用途

| 扩展 | 用途 | 平台 |
|------|------|------|
| sockets | WLS 服务器 TCP 通信 | 全平台（必须） |
| openssl | HTTPS/SSL 支持 | 全平台（必须） |
| event | 高性能事件循环 | 全平台（推荐） |
| opcache | PHP 字节码缓存 | 全平台（推荐） |
| pcntl | 进程控制（fork/signal） | Linux/macOS |
| posix | POSIX 进程函数 | Linux/macOS |
| curl | HTTP 客户端 | 全平台 |
| mbstring | 多字节字符串 | 全平台 |
| fileinfo | 文件类型检测 | 全平台 |
| pdo | 数据库抽象层 | 全平台 |

## 关键类和文件

| 类/文件 | 职责 |
|---------|------|
| `Weline\Framework\Env\Console\Env\Check` | `env:check` 命令实现 |
| `Weline\Framework\Env\Console\Env\Install` | `env:install` 命令实现 |
| `Weline\Framework\Env\Service\EnvChecker` | 环境检查服务（检测扩展/函数） |
| `Weline\Framework\Env\Service\EnvRequirementsCollector` | 收集所有模块的 requirements.php |
| `{Module}/env/requirements.php` | 模块扩展依赖声明 |
| `{Module}/env/script/*.php` | 自定义安装脚本 |

## 排错指南

| 现象 | 原因 | 解决 |
|------|------|------|
| `Call to undefined function socket_create` | sockets 扩展未加载 | `php bin/w env:install` |
| `extension_loaded('event') = false` | event 扩展未安装 | `pecl install event` 或 `env:install` |
| `proc_open has been disabled` | php.ini 中 disable_functions | `env:install` 可自动解禁 |
| Windows 下 DLL 找不到 | ext 目录缺少 DLL 文件 | 从 PECL 下载对应版本 DLL |
| Zend OPcache 未启用 | php.ini 缺少 zend_extension | `env:install` 自动配置 |
| pcntl 在 Windows 不可用 | 该扩展仅支持 Linux/macOS | 正常行为，框架会自动降级 |

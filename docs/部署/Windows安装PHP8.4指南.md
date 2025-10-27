# Windows 11 安装 PHP 8.4 指南

> 本文档提供在 Windows 11 上安装和配置 PHP 8.4 的完整步骤  
> 更新日期：2025-10-18 | 适用于：WelineFramework 项目

---

## 目录

1. [系统要求](#系统要求)
2. [下载 PHP 8.4](#下载-php-84)
3. [安装步骤](#安装步骤)
4. [配置 PHP](#配置-php)
5. [配置环境变量](#配置环境变量)
6. [启用必需扩展](#启用必需扩展)
7. [验证安装](#验证安装)
8. [常见问题](#常见问题)

---

## 系统要求

- **操作系统**：Windows 11 (64位)
- **权限**：管理员权限
- **磁盘空间**：至少 500MB 可用空间
- **Visual C++ 运行库**：Visual C++ Redistributable for Visual Studio 2015-2022

### 检查 Visual C++ 运行库

PHP 8.4 需要 Visual C++ Redistributable。如果尚未安装：

1. 访问：https://aka.ms/vs/17/release/vc_redist.x64.exe
2. 下载并运行安装程序
3. 重启计算机

---

## 下载 PHP 8.4

### 方法一：官方网站下载（推荐）

1. 访问 PHP 官方 Windows 下载页：
   ```
   https://windows.php.net/download/
   ```

2. 选择 **PHP 8.4** 版本，下载以下文件：
   ```
   php-8.4.x-Win32-vs16-x64.zip
   ```
   
   **重要**：
   - 选择 **Thread Safe (TS)** 版本（用于集成服务器）
   - 选择 **x64** 架构（64位系统）
   - 文件大小约 30-40MB

### 方法二：直接下载链接

```
https://windows.php.net/downloads/releases/php-8.4.0-Win32-vs16-x64.zip
```

---

## 安装步骤

### 1. 创建安装目录

推荐安装路径：`C:\php84`

```powershell
# 打开 PowerShell（管理员模式）
New-Item -Path "C:\php84" -ItemType Directory -Force
```

### 2. 解压 PHP

1. 右键点击下载的 `php-8.4.x-Win32-vs16-x64.zip`
2. 选择"全部解压缩"
3. 解压到 `C:\php84`

解压后的目录结构：
```
C:\php84\
  ├── php.exe
  ├── php-cgi.exe
  ├── php.ini-development
  ├── php.ini-production
  ├── ext\              (扩展目录)
  └── ...
```

### 3. 创建 php.ini 配置文件

```powershell
# 复制开发环境配置模板
cd C:\php84
Copy-Item php.ini-development php.ini
```

**生产环境**：使用 `php.ini-production` 模板（更严格的安全设置）

---

## 配置 PHP

### 编辑 php.ini

使用记事本或其他编辑器打开 `C:\php84\php.ini`：

```powershell
notepad C:\php84\php.ini
```

### 基础配置

找到并修改以下配置项（去掉行首的分号 `;` 表示启用）：

```ini
; 1. 扩展目录配置
extension_dir = "C:\php84\ext"

; 2. 时区配置
date.timezone = Asia/Shanghai

; 3. 内存限制
memory_limit = 256M

; 4. 上传文件大小限制
upload_max_filesize = 50M
post_max_size = 50M

; 5. 最大执行时间
max_execution_time = 300
max_input_time = 300

; 6. 错误报告（开发环境）
error_reporting = E_ALL
display_errors = On
display_startup_errors = On

; 7. 日志配置
log_errors = On
error_log = "C:\php84\logs\php_errors.log"
```

### 创建日志目录

```powershell
New-Item -Path "C:\php84\logs" -ItemType Directory -Force
```

---

## 配置环境变量

### 方法一：通过图形界面

1. 按 `Win + X`，选择"系统"
2. 点击"高级系统设置"
3. 点击"环境变量"按钮
4. 在"系统变量"区域，找到 `Path` 变量
5. 点击"编辑"按钮
6. 点击"新建"，添加：
   ```
   C:\php84
   ```
7. 点击"确定"保存所有对话框

### 方法二：通过 PowerShell（管理员模式）

```powershell
# 将 PHP 添加到系统 PATH
[Environment]::SetEnvironmentVariable(
    "Path",
    "$([Environment]::GetEnvironmentVariable('Path', 'Machine'));C:\php84",
    "Machine"
)

# 刷新当前会话环境变量
$env:Path = [System.Environment]::GetEnvironmentVariable("Path","Machine") + ";" + [System.Environment]::GetEnvironmentVariable("Path","User")
```

### 验证环境变量

**重新打开 PowerShell 或 CMD**，然后运行：

```powershell
php --version
```

应该显示类似：
```
PHP 8.4.0 (cli) (built: Nov 21 2024 12:00:00) ( ZTS Visual C++ 2019 x64 )
Copyright (c) The PHP Group
Zend Engine v4.4.0, Copyright (c) Zend Technologies
```

---

## 启用必需扩展

### WelineFramework 必需扩展

根据 `composer.json` 要求，需要启用以下扩展：

在 `C:\php84\php.ini` 中找到扩展配置区域，**去掉行首的分号** `;` 启用：

```ini
; === 必需扩展 (WelineFramework) ===

; 1. 数据库支持
extension=pdo_mysql
extension=pdo_sqlite
extension=mysqli

; 2. 图像处理
extension=gd
extension=exif

; 3. XML/DOM 处理
extension=dom
extension=simplexml
extension=libxml
extension=xml

; 4. 文件处理
extension=fileinfo
extension=zip

; 5. 字符编码
extension=iconv
extension=mbstring

; 6. JSON 支持
extension=json

; 7. CURL 支持
extension=curl

; === 推荐扩展 ===

; OpenSSL（HTTPS 支持）
extension=openssl

; Intl（国际化支持）
extension=intl

; OPcache（性能优化）
zend_extension=opcache

; === OPcache 配置（生产环境推荐）===
opcache.enable=1
opcache.enable_cli=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=8
opcache.max_accelerated_files=10000
opcache.revalidate_freq=2
opcache.fast_shutdown=1
```

### 特殊方法支持

根据 `PHP-Requirements.txt`，需要确保以下函数可用：

在 `php.ini` 中找到 `disable_functions`，确保以下函数**未被禁用**：

```ini
; 找到这一行，确保不包含以下函数：
; disable_functions = 

; 需要的函数：
; - exec
; - putenv
; - symlink
; - proc_open
; - proc_get_status
```

如果 `disable_functions` 行包含这些函数，删除它们。

---

## 验证安装

### 1. 检查 PHP 版本

```powershell
php --version
```

应显示 `PHP 8.4.x`

### 2. 检查已加载扩展

```powershell
php -m
```

输出应包含以下扩展：
```
[PHP Modules]
Core
curl
date
dom
exif
fileinfo
gd
iconv
json
libxml
mbstring
mysqli
mysqlnd
openssl
pcre
PDO
pdo_mysql
pdo_sqlite
Phar
SimpleXML
SPL
sqlite3
standard
xml
xmlreader
xmlwriter
zip
zlib
...

[Zend Modules]
Zend OPcache
```

### 3. 检查必需函数

创建测试文件 `C:\php84\test_functions.php`：

```php
<?php
// 测试必需函数
$required_functions = [
    'exec',
    'putenv',
    'symlink',
    'proc_open',
    'proc_get_status'
];

echo "检查必需函数:\n";
foreach ($required_functions as $func) {
    $status = function_exists($func) ? '✓ 可用' : '✗ 不可用';
    echo "  {$func}: {$status}\n";
}

echo "\n检查必需扩展:\n";
$required_extensions = [
    'pdo', 'pdo_mysql', 'json', 'curl', 'gd', 
    'zip', 'mbstring', 'fileinfo', 'exif',
    'dom', 'simplexml', 'libxml', 'iconv'
];

foreach ($required_extensions as $ext) {
    $status = extension_loaded($ext) ? '✓ 已加载' : '✗ 未加载';
    echo "  {$ext}: {$status}\n";
}

echo "\nPHP 配置信息:\n";
echo "  版本: " . PHP_VERSION . "\n";
echo "  时区: " . ini_get('date.timezone') . "\n";
echo "  内存限制: " . ini_get('memory_limit') . "\n";
echo "  最大上传: " . ini_get('upload_max_filesize') . "\n";
echo "  最大执行时间: " . ini_get('max_execution_time') . "秒\n";
?>
```

运行测试：

```powershell
php C:\php84\test_functions.php
```

### 4. 验证 Composer 兼容性

在项目目录运行：

```powershell
cd E:\WelineFramework\DEV-workspace
php composer.phar diagnose
```

应显示"No issues detected"或类似信息。

---

## 常见问题

### 问题 1：找不到 VCRUNTIME140.dll

**错误信息**：
```
无法启动此程序，因为计算机中丢失 VCRUNTIME140.dll
```

**解决方案**：
安装 Visual C++ Redistributable：
```
https://aka.ms/vs/17/release/vc_redist.x64.exe
```

---

### 问题 2：扩展无法加载

**错误信息**：
```
PHP Warning: PHP Startup: Unable to load dynamic library 'ext\php_xxx.dll'
```

**解决方案**：

1. 检查扩展文件是否存在：
   ```powershell
   dir C:\php84\ext\php_*.dll
   ```

2. 检查 `php.ini` 中的 `extension_dir` 配置：
   ```ini
   extension_dir = "C:\php84\ext"
   ```

3. 确保使用正确的扩展名（不带路径和 `.dll` 后缀）：
   ```ini
   ; ✓ 正确
   extension=gd
   
   ; ✗ 错误
   extension=php_gd.dll
   extension=C:\php84\ext\php_gd.dll
   ```

---

### 问题 3：PHP 命令找不到

**错误信息**：
```
'php' 不是内部或外部命令，也不是可运行的程序或批处理文件。
```

**解决方案**：

1. 确认环境变量已配置：
   ```powershell
   echo $env:Path
   ```
   
   应包含 `C:\php84`

2. 重新打开终端（环境变量需要新会话才生效）

3. 手动刷新环境变量（PowerShell）：
   ```powershell
   $env:Path = [System.Environment]::GetEnvironmentVariable("Path","Machine") + ";" + [System.Environment]::GetEnvironmentVariable("Path","User")
   ```

---

### 问题 4：时区警告

**错误信息**：
```
Warning: date(): It is not safe to rely on the system's timezone settings
```

**解决方案**：

在 `php.ini` 中设置时区：
```ini
date.timezone = Asia/Shanghai
```

中国其他常用时区：
- `Asia/Shanghai` - 北京时间
- `Asia/Chongqing` - 重庆
- `Asia/Hong_Kong` - 香港
- `Asia/Macau` - 澳门

---

### 问题 5：proc_open 被禁用

**错误信息**：
```
proc_open() has been disabled for security reasons
```

**解决方案**：

编辑 `php.ini`，找到 `disable_functions` 行：

```ini
; 修改前
disable_functions = proc_open,proc_get_status,exec,system,shell_exec

; 修改后（移除 WelineFramework 需要的函数）
disable_functions = system,shell_exec
```

**注意**：这些函数在开发环境可以启用，生产环境需谨慎评估安全风险。

---

### 问题 6：Composer 报告 PHP 版本不匹配

**错误信息**：
```
Your PHP version (7.4.x) does not satisfy that requirement.
```

**解决方案**：

1. 确认当前 PHP 版本：
   ```powershell
   php --version
   ```

2. 如果有多个 PHP 版本，检查 PATH 顺序：
   ```powershell
   where.exe php
   ```

3. 确保 `C:\php84` 在其他 PHP 路径之前

4. 清理 Composer 缓存：
   ```powershell
   php composer.phar clear-cache
   php composer.phar install
   ```

---

## 下一步

安装完成后，您可以：

1. **安装 Composer 依赖**：
   ```powershell
   cd E:\WelineFramework\DEV-workspace
   php composer.phar install
   ```

2. **运行 WelineFramework 设置**：
   ```powershell
   php bin/w setup:upgrade
   ```

3. **启动开发服务器**：
   ```powershell
   php bin/w s:sta
   ```

4. **查看后端地址**：
   ```powershell
   cat var/log/server-start.log | Select-String "后端地址"
   ```

---

## 性能优化建议

### 开发环境

```ini
; 开启错误显示
display_errors = On
error_reporting = E_ALL

; 禁用 OPcache（便于调试）
opcache.enable = 0
opcache.enable_cli = 0
```

### 生产环境

```ini
; 关闭错误显示
display_errors = Off
error_reporting = E_ALL & ~E_DEPRECATED & ~E_STRICT
log_errors = On

; 启用 OPcache
opcache.enable = 1
opcache.enable_cli = 1
opcache.memory_consumption = 256
opcache.interned_strings_buffer = 16
opcache.max_accelerated_files = 20000
opcache.validate_timestamps = 0  ; 生产环境关闭文件修改检查

; Realpath 缓存
realpath_cache_size = 4096K
realpath_cache_ttl = 600
```

---

## 相关文档

- [WelineFramework 部署文档](../../部署文档.md)
- [服务器启动指南](../开发/服务器启动规范.md)
- [常见问题修复指南](../常见问题修复指南.md)

---

## 参考资源

- PHP 官方文档：https://www.php.net/manual/zh/
- PHP Windows 下载：https://windows.php.net/download/
- PHP 配置指令：https://www.php.net/manual/zh/ini.list.php
- WelineFramework GitHub：https://gitee.com/aiweline/WelineFramework

---

**维护者**：WelineFramework 开发团队  
**最后更新**：2025-10-18  
**适用版本**：PHP 8.4.x / WelineFramework 最新版


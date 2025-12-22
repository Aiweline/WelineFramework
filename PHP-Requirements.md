# WelineFramework PHP 扩展和函数需求

本文档列出了 WelineFramework 框架运行所需的所有 PHP 扩展和函数。
这些需求基于框架代码分析、composer.json 依赖声明以及实际使用情况。

## PHP 版本要求

- **最低版本**: PHP 8.1
- **推荐版本**: PHP 8.2 或更高版本

---

## 必需扩展 (Required Extensions)

### 1. 数据库支持 (Database Support)

#### 1.1 PDO (PHP Data Objects)
- **扩展名**: `ext-pdo`
- **用途**: 数据库抽象层，框架核心数据库操作
- **Composer 声明**: `"ext-pdo": "*"`
- **代码位置**: 
  - `app/code/Weline/Framework/Database/Connection/Adapter/Mysql/Connector.php`
  - `app/code/Weline/Framework/Database/Connection/Adapter/Sqlite/Connector.php`

#### 1.2 PDO MySQL
- **扩展名**: `ext-pdo_mysql` 或 `pdo_mysql`
- **用途**: MySQL 数据库支持
- **代码位置**: MySQL 连接器使用

#### 1.3 PDO SQLite
- **扩展名**: `ext-pdo_sqlite` 或 `pdo_sqlite`
- **用途**: SQLite 数据库支持
- **代码位置**: SQLite 连接器使用

#### 1.4 PDO PostgreSQL
- **扩展名**: `ext-pdo_pgsql` 或 `pdo_pgsql`
- **用途**: PostgreSQL 数据库支持（通过 PDO）
- **代码位置**: 
  - `app/code/Weline/Framework/Database/Connection/Adapter/Pgsql/Connector.php`
- **状态**: 可选（使用 PostgreSQL 数据库时需要）
- **Windows 安装**: 扩展文件通常包含在 PHP 安装包中（`php_pdo_pgsql.dll`）
- **Linux 安装**: `apt-get install php-pgsql` 或 `yum install php-pgsql`

#### 1.5 PostgreSQL
- **扩展名**: `pgsql`
- **用途**: PostgreSQL 数据库原生支持
- **代码位置**: PostgreSQL 连接器使用
- **状态**: 可选（使用 PostgreSQL 数据库时需要）
- **Windows 安装**: 扩展文件通常包含在 PHP 安装包中（`php_pgsql.dll`）
- **Linux 安装**: 通常与 `php-pgsql` 包一起安装

#### 1.6 MySQLi
- **扩展名**: `mysqli`
- **用途**: MySQL 数据库扩展支持（部分功能需要）
- **Dockerfile 安装**: `mysqli`

---

### 2. 图像处理 (Image Processing)

#### 2.1 GD
- **扩展名**: `ext-gd` 或 `gd`
- **用途**: 图像创建和处理
- **Composer 声明**: `"ext-gd": "*"`
- **代码位置**:
  - `app/code/Weline/MediaManager/Controller/Image.php`
  - `app/code/Weline/ElFinderFileManager/view/statics/php/plugins/Watermark/plugin.php`
- **依赖库**: 需要 `libfreetype6-dev`, `libjpeg62-turbo-dev`, `libpng-dev`, `libgd-dev`

#### 2.2 EXIF
- **扩展名**: `ext-exif` 或 `exif`
- **用途**: 读取图像元数据（EXIF 信息）
- **Composer 声明**: `"ext-exif": "*"`
- **Dockerfile 安装**: `exif`

---

### 3. XML/DOM 处理 (XML/DOM Processing)

#### 3.1 DOM
- **扩展名**: `ext-dom` 或 `dom`
- **用途**: DOM 文档对象模型操作
- **Composer 声明**: `"ext-dom": "*"`
- **代码位置**: XML 配置文件解析、模板处理

#### 3.2 SimpleXML
- **扩展名**: `ext-simplexml` 或 `simplexml`
- **用途**: 简化 XML 解析
- **Composer 声明**: `"ext-simplexml": "*"`
- **代码位置**: XML 配置文件读取

#### 3.3 LibXML
- **扩展名**: `ext-libxml` 或 `libxml`
- **用途**: XML 解析库支持
- **Composer 声明**: `"ext-libxml": "*"`
- **依赖**: DOM 和 SimpleXML 的基础库

#### 3.4 XML
- **扩展名**: `xml`
- **用途**: XML 解析支持
- **说明**: 通常与 libxml 一起使用

---

### 4. 文件处理 (File Processing)

#### 4.1 FileInfo
- **扩展名**: `ext-fileinfo` 或 `fileinfo`
- **用途**: 文件类型检测和 MIME 类型识别
- **Composer 声明**: `"ext-fileinfo": "*"`
- **代码位置**: 文件上传、媒体管理

#### 4.2 ZIP
- **扩展名**: `ext-zip` 或 `zip`
- **用途**: ZIP 文件压缩和解压
- **Composer 声明**: `"ext-zip": "*"`
- **代码位置**: 模块打包、文件压缩

---

### 5. 字符编码 (Character Encoding)

#### 5.1 Iconv
- **扩展名**: `ext-iconv` 或 `iconv`
- **用途**: 字符集转换
- **Composer 声明**: `"ext-iconv": "*"`
- **代码位置**: 多语言支持、字符编码转换

#### 5.2 MBString
- **扩展名**: `mbstring`
- **用途**: 多字节字符串处理（UTF-8 支持）
- **Composer 声明**: 通过依赖包间接需要
- **Dockerfile 安装**: `mbstring`
- **代码位置**: 字符串处理、国际化

---

### 6. JSON 支持 (JSON Support)

#### 6.1 JSON
- **扩展名**: `ext-json` 或 `json`
- **用途**: JSON 数据编码和解码
- **Composer 声明**: `"ext-json": "*"`
- **代码位置**: API 响应、数据序列化

---

### 7. 网络支持 (Network Support)

#### 7.1 cURL
- **扩展名**: `ext-curl` 或 `curl`
- **用途**: HTTP 客户端请求
- **Composer 声明**: `"ext-curl": "*"`
- **代码位置**: 
  - `app/code/Weline/Cdn/Adapter/Cloudflare.php`
  - Guzzle HTTP 客户端依赖

---

### 8. 加密和安全 (Encryption & Security)

#### 8.1 OpenSSL
- **扩展名**: `openssl`
- **用途**: HTTPS 支持、加密解密、证书验证
- **状态**: 强烈推荐（生产环境必需）
- **代码位置**: HTTPS 请求、API 安全通信

---

### 9. 国际化 (Internationalization)

#### 9.1 Intl
- **扩展名**: `intl`
- **用途**: 国际化支持（日期、数字、货币格式化）
- **状态**: 推荐（symfony/intl 依赖）
- **Composer 依赖**: `symfony/intl: ^5.2`
- **代码位置**: 多语言支持、本地化

---

### 10. 数学计算 (Mathematics)

#### 10.1 BCMath
- **扩展名**: `bcmath`
- **用途**: 任意精度数学计算
- **Dockerfile 安装**: `bcmath`
- **代码位置**: 精确计算、金融相关功能

---

### 11. 进程控制 (Process Control)

#### 11.1 PCNTL
- **扩展名**: `pcntl`
- **用途**: 进程控制（Unix/Linux 系统）
- **Dockerfile 安装**: `pcntl`
- **代码位置**: 后台任务、进程管理
- **注意**: Windows 系统不支持此扩展

---

### 12. 性能优化 (Performance Optimization)

#### 12.1 OPcache
- **扩展名**: `opcache` (Zend 扩展)
- **用途**: PHP 操作码缓存，提升性能
- **状态**: 强烈推荐（生产环境必需）
- **Composer 配置**: `"opcache-autoloader": true`
- **配置建议**:
  ```ini
  opcache.enable=1
  opcache.enable_cli=1
  opcache.memory_consumption=128
  opcache.interned_strings_buffer=8
  opcache.max_accelerated_files=10000
  opcache.revalidate_freq=2
  opcache.fast_shutdown=1
  ```

#### 12.2 APCu
- **扩展名**: `apcu`
- **用途**: 用户数据缓存
- **Composer 配置**: `"apcu-autoloader": true`
- **状态**: 推荐（可选）

---

## 必需函数 (Required Functions)

以下函数必须在 `php.ini` 的 `disable_functions` 中**未被禁用**：

### 1. 系统执行函数

#### 1.1 exec()
- **用途**: 执行系统命令
- **代码位置**: 
  - `app/code/Weline/Framework/App/System.php` (exec, win_exec, linux_exec)
  - `app/code/Weline/Framework/Console/Console/Server/Start.php`
- **必需性**: **必需**
- **安全提示**: 确保输入验证和权限控制

#### 1.2 proc_open()
- **用途**: 执行命令并打开进程文件指针
- **代码位置**:
  - `app/code/Weline/ElFinderFileManager/view/statics/php/elFinder.class.php`
  - `app/code/Weline/Cron/Helper/Process.php`
  - `app/code/Weline/Framework/UnitTest/Console/PhpUnit/Run.php`
- **必需性**: **必需**
- **说明**: 用于后台进程管理、文件管理器操作

#### 1.3 proc_get_status()
- **用途**: 获取由 proc_open() 打开的进程信息
- **代码位置**: 
  - `app/code/Weline/Framework/UnitTest/Console/PhpUnit/Run.php`
- **必需性**: **必需**
- **说明**: 与 proc_open() 配合使用

#### 1.4 shell_exec()
- **用途**: 通过 shell 执行命令并返回完整输出
- **代码位置**: 
  - `app/code/Weline/Framework/UnitTest/Console/PhpUnit/Run.php`
- **必需性**: **可选**（备用方案）
- **说明**: 当 exec() 和 proc_open() 不可用时使用

---

### 2. 环境变量函数

#### 2.1 putenv()
- **用途**: 设置环境变量
- **代码位置**: 多处使用
- **必需性**: **必需**
- **说明**: 用于配置环境变量、Composer 环境设置

#### 2.2 getenv()
- **用途**: 获取环境变量
- **代码位置**: 配置读取
- **必需性**: **必需**

---

### 3. 文件系统函数

#### 3.1 symlink()
- **用途**: 创建符号链接
- **代码位置**: 部署、静态资源链接
- **必需性**: **必需**
- **说明**: 用于创建符号链接（Windows 需要管理员权限）

#### 3.2 readlink()
- **用途**: 读取符号链接目标
- **代码位置**: 符号链接处理
- **必需性**: **推荐**

---

### 4. 进程控制函数 (Linux/Unix)

#### 4.1 posix_kill()
- **扩展**: `posix` 扩展
- **用途**: 向进程发送信号
- **代码位置**: 
  - `app/code/Weline/Framework/Console/Console/Server/Server.php`
- **必需性**: **可选**（仅 Linux/Unix 系统）
- **说明**: Windows 系统不支持

---

## 扩展分类总结

### 核心必需扩展 (Core Required)
以下扩展是框架运行的核心依赖，**必须安装**：

1. `ext-pdo` - PDO 数据库抽象层
2. `ext-pdo_mysql` - MySQL 数据库支持
3. `ext-pdo_sqlite` - SQLite 数据库支持（可选，但推荐）
4. `ext-json` - JSON 支持
5. `ext-curl` - HTTP 客户端
6. `ext-mbstring` - 多字节字符串处理
7. `ext-iconv` - 字符编码转换
8. `ext-dom` - DOM 操作
9. `ext-simplexml` - XML 解析
10. `ext-libxml` - XML 库支持
11. `ext-fileinfo` - 文件类型检测
12. `ext-zip` - ZIP 文件处理
13. `ext-gd` - 图像处理
14. `ext-exif` - 图像元数据

### 数据库扩展（根据使用的数据库选择）
以下扩展根据实际使用的数据库类型选择安装：

1. `ext-pdo_pgsql` - PostgreSQL 数据库支持（使用 PostgreSQL 时必需）
2. `pgsql` - PostgreSQL 原生扩展（使用 PostgreSQL 时推荐）

### 推荐扩展 (Recommended)
以下扩展强烈推荐安装，用于生产环境：

1. `openssl` - HTTPS 和加密支持
2. `intl` - 国际化支持
3. `opcache` - 性能优化（Zend 扩展）
4. `mysqli` - MySQL 扩展支持
5. `bcmath` - 精确数学计算

### 可选扩展 (Optional)
以下扩展根据使用场景可选：

1. `pcntl` - 进程控制（仅 Linux/Unix）
2. `apcu` - 用户数据缓存
3. `posix` - POSIX 函数（仅 Linux/Unix）
4. `pdo_pgsql` - PostgreSQL PDO 驱动（使用 PostgreSQL 数据库时）
5. `pgsql` - PostgreSQL 原生扩展（使用 PostgreSQL 数据库时）

---

## PHP 配置要求

### php.ini 配置建议

```ini
; 内存限制
memory_limit = 512M

; 上传文件大小
upload_max_filesize = 100M
post_max_size = 100M

; 执行时间
max_execution_time = 300
max_input_time = 300

; 时区
date.timezone = Asia/Shanghai

; 禁用函数（确保以下函数未被禁用）
; disable_functions = 
; 注意：exec, putenv, symlink, proc_open, proc_get_status 必须可用

; OPcache 配置（生产环境）
opcache.enable=1
opcache.enable_cli=1
opcache.memory_consumption=128
opcache.interned_strings_buffer=8
opcache.max_accelerated_files=10000
opcache.revalidate_freq=2
opcache.fast_shutdown=1
```

### 扩展启用配置

#### 数据库扩展配置

**MySQL 扩展**（默认已启用）：
```ini
extension=pdo_mysql
extension=mysqli
```

**SQLite 扩展**（默认已启用）：
```ini
extension=pdo_sqlite
extension=sqlite3
```

**PostgreSQL 扩展**（使用 PostgreSQL 数据库时需要启用）：
```ini
; PostgreSQL PDO 驱动（必需）
extension=pdo_pgsql

; PostgreSQL 原生扩展（推荐）
extension=pgsql
```

**注意**：
- 在 Windows 上，扩展文件名可能为 `php_pdo_pgsql.dll` 和 `php_pgsql.dll`
- 确保扩展文件存在于 `extension_dir` 指定的目录中
- 如果扩展被注释（行首有 `;`），需要去掉分号来启用

---

## 扩展安装和启用指南

### Windows 系统

#### 1. 查找 PHP 配置文件

运行以下命令查找 `php.ini` 文件位置：
```powershell
php -r "echo php_ini_loaded_file();"
```

或使用：
```powershell
php --ini
```

#### 2. 检查扩展文件

确认扩展文件存在于扩展目录：
```powershell
php -r "echo ini_get('extension_dir');"
```

检查该目录中是否存在：
- `php_pdo_pgsql.dll` 或 `pdo_pgsql.dll`（PostgreSQL PDO 驱动）
- `php_pgsql.dll` 或 `pgsql.dll`（PostgreSQL 原生扩展）

**如果扩展文件不存在**：
1. 从 PHP 官网下载对应版本的扩展 DLL 文件
2. 将 DLL 文件放到扩展目录（通常是 `ext` 文件夹）
3. 确保 DLL 文件版本与 PHP 版本匹配

#### 3. 启用扩展

编辑 `php.ini` 文件，找到扩展配置区域（搜索 `extension=`），添加或取消注释：

```ini
; PostgreSQL 扩展（去掉分号启用）
extension=pdo_pgsql
extension=pgsql
```

**注意**：
- 如果行首有分号 `;`，需要去掉分号来启用扩展
- 扩展名不需要 `.dll` 后缀和完整路径
- 确保 `extension_dir` 配置正确指向扩展目录

#### 4. 重启服务

修改配置后，需要重启：
- Web 服务器（Apache/Nginx）
- PHP-FPM 服务
- 开发服务器（如果使用内置服务器）

### Linux/Unix 系统

#### 1. 安装 PostgreSQL 扩展

**Ubuntu/Debian**：
```bash
sudo apt-get update
sudo apt-get install php-pgsql
```

**CentOS/RHEL**：
```bash
sudo yum install php-pgsql
# 或使用 dnf (Fedora/CentOS 8+)
sudo dnf install php-pgsql
```

**编译安装**：
```bash
# 需要 PostgreSQL 开发库
sudo apt-get install libpq-dev  # Ubuntu/Debian
sudo yum install postgresql-devel  # CentOS/RHEL

# 编译 PHP 时添加 --with-pdo-pgsql 和 --with-pgsql
```

#### 2. 启用扩展

通常安装包会自动配置，检查 `php.ini` 或扩展配置文件：

```bash
# 查找扩展配置文件
php --ini

# 检查扩展是否已启用
php -m | grep pgsql
```

如果未启用，在 `php.ini` 中添加：
```ini
extension=pdo_pgsql
extension=pgsql
```

#### 3. 重启服务

```bash
# Apache
sudo systemctl restart apache2  # Ubuntu/Debian
sudo systemctl restart httpd    # CentOS/RHEL

# PHP-FPM
sudo systemctl restart php-fpm
# 或
sudo systemctl restart php8.4-fpm  # 根据 PHP 版本调整
```

### 验证扩展安装

#### 检查扩展是否加载

```bash
php -m
```

应包含以下扩展：
- pdo
- pdo_mysql
- pdo_sqlite
- pdo_pgsql（使用 PostgreSQL 时）
- pgsql（使用 PostgreSQL 时）
- json
- curl
- mbstring
- iconv
- dom
- simplexml
- libxml
- fileinfo
- zip
- gd
- exif
- mysqli (推荐)
- openssl (推荐)
- intl (推荐)
- opcache (推荐)
- bcmath (推荐)

#### 检查 PDO 驱动

```bash
php -r "print_r(PDO::getAvailableDrivers());"
```

应看到以下驱动（根据使用的数据库）：
- `mysql` - MySQL 驱动
- `sqlite` - SQLite 驱动
- `pgsql` - PostgreSQL 驱动（使用 PostgreSQL 时）
- `odbc` - ODBC 驱动（如果启用）

### 检查函数是否可用

创建测试文件 `test_functions.php`:

```php
<?php
$required_functions = [
    'exec',
    'putenv',
    'symlink',
    'proc_open',
    'proc_get_status',
    'shell_exec',
    'getenv'
];

echo "检查必需函数:\n";
foreach ($required_functions as $func) {
    $status = function_exists($func) ? '✓ 可用' : '✗ 不可用';
    echo "  {$func}: {$status}\n";
}

$required_extensions = [
    'pdo', 'pdo_mysql', 'pdo_sqlite', 'json', 'curl', 
    'gd', 'zip', 'mbstring', 'fileinfo', 'exif',
    'dom', 'simplexml', 'libxml', 'iconv'
];

// 可选扩展（根据使用的数据库）
$optional_extensions = [
    'pdo_pgsql',  // PostgreSQL PDO 驱动
    'pgsql'       // PostgreSQL 原生扩展
];

echo "\n检查可选扩展（数据库相关）:\n";
foreach ($optional_extensions as $ext) {
    $status = extension_loaded($ext) ? '✓ 已加载' : '○ 未加载（可选）';
    echo "  {$ext}: {$status}\n";
}

// 检查 PDO 驱动
echo "\n检查 PDO 驱动:\n";
$pdo_drivers = PDO::getAvailableDrivers();
foreach ($pdo_drivers as $driver) {
    echo "  - {$driver}\n";
}
if (in_array('pgsql', $pdo_drivers)) {
    echo "\n✓ PostgreSQL PDO 驱动已可用\n";
} else {
    echo "\n○ PostgreSQL PDO 驱动未启用（使用 PostgreSQL 时需要）\n";
}

echo "\n检查必需扩展:\n";
foreach ($required_extensions as $ext) {
    $status = extension_loaded($ext) ? '✓ 已加载' : '✗ 未加载';
    echo "  {$ext}: {$status}\n";
}
?>
```

运行测试：
```bash
php test_functions.php
```

---

## 平台差异说明

### Windows 系统
- ❌ 不支持 `pcntl` 扩展
- ❌ 不支持 `posix` 扩展
- ⚠️ `symlink()` 需要管理员权限或启用开发者模式
- ✅ 支持所有其他扩展和函数

### Linux/Unix 系统
- ✅ 支持所有扩展和函数
- ✅ `pcntl` 和 `posix` 可用

---

## 参考文档

- **Composer 依赖**: `composer.json`
- **Docker 配置**: `Dockerfile`
- **Windows 安装指南**: `docs/部署/Windows安装PHP8.4指南.md`
- **代码使用位置**: 见各扩展/函数说明

---

## 常见问题

### PostgreSQL 扩展相关问题

#### 问题 1：驱动不存在错误

**错误信息**：
```
DB Error:驱动不存在：pgsql,可用驱动列表：mysql,odbc,sqlite
```

**解决方案**：
1. 检查扩展是否已启用：
   ```bash
   php -m | grep pgsql
   ```

2. 检查 PDO 驱动：
   ```bash
   php -r "print_r(PDO::getAvailableDrivers());"
   ```

3. 如果扩展已加载但 PDO 驱动不可用：
   - 确保 `pdo_pgsql` 扩展已启用（不仅仅是 `pgsql`）
   - 检查 `php.ini` 中 `extension=pdo_pgsql` 未被注释
   - 重启 Web 服务器或 PHP 服务

4. 如果扩展文件不存在：
   - Windows: 从 PHP 官网下载对应版本的扩展 DLL
   - Linux: 安装 `php-pgsql` 包

#### 问题 2：扩展文件找不到

**Windows**：
- 检查扩展目录：`php -r "echo ini_get('extension_dir');"`
- 确认扩展文件存在且版本匹配
- 检查 `extension_dir` 配置是否正确

**Linux**：
- 使用包管理器安装：`apt-get install php-pgsql` 或 `yum install php-pgsql`
- 检查扩展配置文件：`/etc/php/8.4/mods-available/pgsql.ini`

#### 问题 3：修改配置后无效

**可能原因**：
1. 修改了错误的 `php.ini` 文件（可能有多个配置文件）
2. 服务未重启
3. 扩展文件路径不正确

**解决方案**：
1. 确认修改的是正确的 `php.ini`：
   ```bash
   php -r "echo php_ini_loaded_file();"
   ```

2. 重启所有相关服务：
   - Web 服务器（Apache/Nginx）
   - PHP-FPM
   - 开发服务器

3. 检查 `extension_dir` 配置是否正确

---

## 更新日志

- **2025-01**: 添加 PostgreSQL 扩展安装和启用指南
  - 添加 PDO PostgreSQL 和 PostgreSQL 扩展说明
  - 添加 Windows 和 Linux 系统的安装步骤
  - 添加扩展启用配置说明
  - 添加常见问题解决方案
- **2024-12**: 初始版本，基于框架代码分析整理
- 基于 WelineFramework 代码库分析
- 参考 composer.json、Dockerfile 和实际代码使用情况

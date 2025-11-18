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

#### 1.4 MySQLi
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

---

## 验证安装

### 检查扩展是否加载

```bash
php -m
```

应包含以下扩展：
- pdo
- pdo_mysql
- pdo_sqlite
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

## 更新日志

- **2024-12**: 初始版本，基于框架代码分析整理
- 基于 WelineFramework 代码库分析
- 参考 composer.json、Dockerfile 和实际代码使用情况

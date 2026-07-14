# Windows 下 event 扩展编译说明

Windows 固定使用 Dispatcher 拓扑。`event` 是 Dispatcher 的事件循环优化，不是启动或 HTTPS 的必要条件；没有可信 DLL 时，WLS 保持 `Dispatcher + stream SSL + 有界 stream_select`，不改写拓扑。

PHP 8.4 的默认安装路径不再要求人工搬运 DLL：`server:start` 在创建任何 Master、Dispatcher 或 Worker 前，从官方 PECL event 3.1.4 发布目录选择精确的 `8.4 + TS/NTS + VS17 + x64/x86` 包，以内置固定 SHA-256 校验，且只提取 `php_event.dll` 与同包 `pthreadVC2.dll`。已有 DLL 会先保留备份，再原子切换；随后必须由同一 `PHP_BINARY` 新进程实际加载 `EventBase/Event`。任何一步失败都不会尝试相邻 PHP 版本或未知二进制。

## 一、ABI 门禁（必须完全匹配）

只能使用与当前 `PHP_BINARY` 完全匹配的 DLL：

- PHP major/minor，例如 PHP 8.4 只能使用为 8.4 构建的 DLL；
- x64/x86；
- TS/NTS；
- Visual Studio toolset；
- release/debug 构建。

禁止把 PHP 8.2/8.3 的 `php_event.dll` 放到 PHP 8.4 中试运行。DLL 文件“存在”不是能力证明；`server:start` 只有在同一 `PHP_BINARY` 的新子进程中同时验证 `extension_loaded('event')`、`EventBase` 和 `Event` 后才会启用。

若 [PECL Windows event 发布目录](https://windows.php.net/downloads/pecl/releases/event/) 没有与当前 ABI 完全匹配且已在框架中固定摘要的包，保持 Windows 默认 Dispatcher 运行时，或按下文使用对应 PHP 源码自行编译。WLS 不动态选择“最新包”，也不加载未验证 DLL。

---

## 二、从源码编译（需 Visual Studio + PHP SDK + libevent）

event 扩展依赖 **libevent**，Windows 上需先编译 libevent，再在 PHP 扩展构建环境中编译 event。

### 1. 环境准备

- **Windows 10/11 64 位**
- **Visual Studio 2019 或 2022**（带「使用 C++ 的桌面开发」、Windows SDK）
- **PHP SDK（php-sdk-binary-tools）**：用于在 Windows 上构建 PHP 扩展
- **libevent**：需先自行编译为 Windows 库（含头文件和 .lib/.dll）

### 2. 获取 PHP SDK

```bat
git clone https://github.com/php/php-sdk-binary-tools.git C:\php-sdk
cd C:\php-sdk
git checkout php-sdk-2.2.2
```

启动构建环境（按你安装的 VS 版本选一个）：

- VS 2022 64 位：`phpsdk-vs17-x64.bat`
- VS 2019 64 位：`phpsdk-vs16-x64.bat`

### 3. 编译 libevent（简要）

- 下载 libevent 源码：https://github.com/libevent/libevent/releases  
- 用 Visual Studio 或 CMake 在 Windows 下编译，得到：
  - `include` 目录（头文件）
  - `libevent.lib`、`libevent_core.lib` 等（或 DLL + 导入库）
- 记下安装目录，例如 `C:\libevent`（下面用 `LIBEVENT_DIR` 表示）

若使用 OpenSSL，需先编译或安装 OpenSSL，并在编译 libevent 时指定。

### 4. 构建 event 扩展

在 PHP SDK 的 shell 中（已执行上述 `phpsdk-vs17-x64.bat` 等）：

```bat
phpsdk_buildtree phpmaster
cd C:\php-sdk\phpmaster\vc17\x64\php-src
```

若还没有 PHP 源码，先拉取对应分支（例如 PHP 8.4）：

```bat
git clone https://github.com/php/php-src.git
cd php-src
git checkout PHP-8.4
```

下载 event 扩展源码并解压到 `php-src\ext\event`：

```bat
pecl download event
tar -xvf event-*.tgz
move event-* event
```

依赖库（PHP SDK 2.2 可用 phpsdk_deps 拉取部分依赖，libevent 需自行指定）：

```bat
phpsdk_deps --update --branch PHP-8.4
```

配置并编译（将 `LIBEVENT_DIR` 换成你的 libevent 安装路径）：

```bat
buildconf
configure --disable-all --enable-cli --with-event=LIBEVENT_DIR
nmake
```

在生成的 `Release_TS` 或 `Release_NTS` 目录中会得到 **php_event.dll**，只能将它复制到与本次编译 PHP ABI 完全一致的 **ext** 目录，并在该 `PHP_BINARY` 实际加载的 **php.ini** 中添加 `extension=event`。

安装后先独立验证：

```bat
php -r "echo PHP_BINARY, PHP_EOL, PHP_VERSION, PHP_EOL; exit(extension_loaded('event') && class_exists('EventBase') && class_exists('Event') ? 0 : 1);"
php bin/w server:doctor
```

只有两条命令都指向预期 PHP 且验证成功，WLS 才会选择 event loop。

### 5. 参考链接

- PHP SDK 使用：https://github.com/php/php-sdk-binary-tools  
- PHP Windows 构建步骤：https://wiki.php.net/internals/windows/stepbystepbuild_sdk_2  
- libevent：https://github.com/libevent/libevent  
- PECL event：https://pecl.php.net/package/event  

---

## 三、无法编译时的正常运行方案

- 保持框架要求的 PHP 8.4，使用 Windows 默认 `Dispatcher + stream/select`。
- HTTPS 由当前 PHP 的 OpenSSL 扩展承担；`server:start` 会用同一 `PHP_BINARY` 验证 OpenSSL，不会因 event 缺失关闭 HTTPS。
- 运维对照或诊断时显式使用 `php bin/w server:start --dispatcher`；Windows 上 `--direct`、`--no-dispatcher` 和 `independent` 会在任何 Master/Worker 创建前被拒绝。

# Windows 下 event 扩展编译说明

Windows 上官方预编译的 event DLL 可能没有覆盖所有 PHP 版本（例如 PHP 8.4 暂无）。可按下面顺序处理。

## 一、优先：用相邻 PHP 版本的预编译 DLL（推荐）

1. 打开 **https://windows.php.net/downloads/pecl/releases/event/**
2. 进入 **3.0.6** 或 **3.0.7** 等 3.0.x 目录
3. 若没有当前 PHP 版本（如 8.4），可试 **8.3** 或 **8.2** 的 zip（多数情况下兼容）
4. 选择与当前 PHP 一致的：**nts/ts**、**x64/x86**，文件名形如：
   - `php_event-3.0.6-8.3-nts-vs16-x64.zip`
   - `php_event-3.0.6-8.2-nts-vs16-x64.zip`
5. 解压后将 **php_event.dll** 放入 PHP 的 **ext** 目录，在 **php.ini** 中添加 `extension=event`，重启

若 8.3/8.2 的 DLL 在 8.4 上无法加载，再考虑从源码编译。

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

在生成的 `Release_TS` 或 `Release_NTS` 目录中会得到 **php_event.dll**，将其复制到现有 PHP 的 **ext** 目录，并在 **php.ini** 中添加 `extension=event`。

### 5. 参考链接

- PHP SDK 使用：https://github.com/php/php-sdk-binary-tools  
- PHP Windows 构建步骤：https://wiki.php.net/internals/windows/stepbystepbuild_sdk_2  
- libevent：https://github.com/libevent/libevent  
- PECL event：https://pecl.php.net/package/event  

---

## 三、无法编译时的替代方案

- 使用 **PHP 8.2 或 8.3**（有现成 event DLL）运行 Weline Server  
- 或使用 **`php bin/w server:start --cli`** 回退到 PHP 内置服务器（无 HTTPS，不依赖 event）

# WelineFramework

![WelineFramework 封面](./docs/assets/readme/weline-framework-cover.zh-CN.png)

**WelineFramework** 是 PHP 8.4+ 模块化业务框架。招牌运行时 **WLS** 让应用**常驻内存**——HTTP Worker、Session Server、Memory Server 与热重载——同时传统 FPM 仍是一等部署路径。

[官网](https://www.aiweline.com) ·
[开发者入口](./docs/weline/开发者入口.md) ·
[文档](./docs/README.md) ·
[架构](./docs/weline/README.md) ·
[WLS](./app/code/Weline/Server/doc/README.md) ·
[多语言 README](./docs/readme/README.md) ·
[AI 工程入口](./AGENTS.md)

![PHP 8.4+](https://img.shields.io/badge/PHP-8.4%2B-777bb4?logo=php&logoColor=white)
![Composer 2.7+](https://img.shields.io/badge/Composer-2.7%2B-885630?logo=composer&logoColor=white)
![Runtime FPM + WLS](https://img.shields.io/badge/runtime-FPM%20%2B%20WLS-0f766e)
![WLS in-memory](https://img.shields.io/badge/WLS-in--memory%20runtime-0f766e)
![i18n first](https://img.shields.io/badge/i18n-first-2563eb)
![License proprietary](https://img.shields.io/badge/license-proprietary-lightgrey)

[English](./README.md) |
[简体中文](./README.zh-CN.md) |
[日本語](./docs/readme/README.ja.md) |
[한국어](./docs/readme/README.ko.md) |
[Deutsch](./docs/readme/README.de.md) |
[Français](./docs/readme/README.fr.md) |
[更多语言](./docs/readme/README.md)

---

WelineFramework 把模块生命周期、自动路由、属性驱动 ORM、事件/Hook、后台 ACL、主题、i18n 与 CLI 放进同一套工程模型，让业务能力以可安装、可升级的模块交付。

### WLS 与 FPM

| | **WLS**（招牌能力） | **FPM**（同样一等） |
|---|---|---|
| 模型 | 长驻内存的框架运行时 | 经典请求 / 进程模型 |
| 启动 | `php bin/w server:start` | Nginx/Apache + php-fpm |
| 能力 | HTTP Worker、Session/Memory、维护 Worker、热重载、运行时治理 | 熟悉的传统 PHP 部署 |

> WLS 是**框架运行时**，不是通用 HTTP 调试入口。需要常驻 Worker 与进程内服务时用 WLS；需要经典栈时用 FPM。

## 快速开始

Linux / macOS / Git Bash:

```bash
curl -fsSL https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.sh | bash -s --
```

Windows PowerShell:

```powershell
$f="$env:TEMP\weline-bootstrap.ps1"; irm 'https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.ps1' -OutFile $f; & $f
```

源码纯净安装：

```bash
git clone https://gitee.com/aiweline/WelineFramework.git weline
cd weline
composer install
php bin/w command:upgrade
```

## 为什么选择 Weline

- **模块原生**：模块独立维护注册、配置、权限、菜单、事件、Hook、模板资源和安装升级。
- **约定驱动**：Controller 由框架发现并生成路由，Model 通过 PHP 属性声明表、字段和索引。
- **WLS 常驻内存运行时**：Worker、Session/Memory 与热重载让应用常驻进程；需要时仍可走经典 FPM。
- **开发者可运维**：`bin/w` 覆盖安装、升级、缓存、模块、迁移、路由、WLS、队列、邮局、SMTP 和诊断。

## 继续阅读

- [开发者入口](./docs/weline/开发者入口.md)：完整能力介绍、安装说明、开发路径和命令速查。
- [项目文档索引](./docs/README.md)：项目级文档总入口。
- [架构总览](./docs/weline/README.md)：框架分层、运行时、路由、ORM、事件与扩展。
- [WLS 文档导航](./app/code/Weline/Server/doc/README.md)：WLS 运行时与服务编排文档。
- [多语言 README](./docs/readme/README.md)：全球开发者入口索引。

更多产品能力、行业方案和业务场景，请访问 [www.aiweline.com](https://www.aiweline.com)。

## 许可证

本仓库许可证以 [composer.json](./composer.json) 中的 `license` 字段为准。

# WelineFramework

WelineFramework 是一个面向模块化 Web 应用、后台系统和电商业务的 PHP 框架。框架以模块边界、自动路由、ORM 模型属性、事件与 Hook、主题模板、后台 ACL、国际化、命令行工具和 Weline 框架内置服务器（WLS）为核心，目标是在复杂业务项目中保持可扩展、可部署、可验证和可长期维护。

本 README 面向开发者，作为进入仓库的技术入口。若需要更完整的架构、模块或专题材料，请按本文档的文档地图继续阅读。

## 多语言版本

简体中文根 README 是权威开发者入口。本地化版本提供开发者快速入门摘要，见 [多语言 README 索引](./docs/readme/README.md)。

| Language | README | Language | README |
|---|---|---|---|
| English | [README.en.md](./docs/readme/README.en.md) | Español | [README.es.md](./docs/readme/README.es.md) |
| Français | [README.fr.md](./docs/readme/README.fr.md) | Deutsch | [README.de.md](./docs/readme/README.de.md) |
| Português do Brasil | [README.pt-BR.md](./docs/readme/README.pt-BR.md) | Русский | [README.ru.md](./docs/readme/README.ru.md) |
| 日本語 | [README.ja.md](./docs/readme/README.ja.md) | 한국어 | [README.ko.md](./docs/readme/README.ko.md) |
| العربية | [README.ar.md](./docs/readme/README.ar.md) | हिन्दी | [README.hi.md](./docs/readme/README.hi.md) |
| Bahasa Indonesia | [README.id.md](./docs/readme/README.id.md) | Tiếng Việt | [README.vi.md](./docs/readme/README.vi.md) |
| ไทย | [README.th.md](./docs/readme/README.th.md) | Türkçe | [README.tr.md](./docs/readme/README.tr.md) |
| Italiano | [README.it.md](./docs/readme/README.it.md) | Nederlands | [README.nl.md](./docs/readme/README.nl.md) |
| Polski | [README.pl.md](./docs/readme/README.pl.md) | فارسی | [README.fa.md](./docs/readme/README.fa.md) |
| Українська | [README.uk.md](./docs/readme/README.uk.md) | বাংলা | [README.bn.md](./docs/readme/README.bn.md) |
| اردو | [README.ur.md](./docs/readme/README.ur.md) | Bahasa Melayu | [README.ms.md](./docs/readme/README.ms.md) |
| Filipino | [README.tl.md](./docs/readme/README.tl.md) | Kiswahili | [README.sw.md](./docs/readme/README.sw.md) |
| 繁體中文 | [README.zh-Hant.md](./docs/readme/README.zh-Hant.md) |  |  |

## 架构概览

WelineFramework 的开发模型围绕“模块提供能力，框架编排能力”展开：

| 能力 | 开发者关注点 |
|---|---|
| 模块系统 | 模块注册、依赖、配置、菜单、权限、事件、Hook、模板资源和安装升级独立维护 |
| 路由系统 | Controller 由框架发现并生成路由；新增或调整 Controller 后执行 `php bin/w setup:upgrade --route` |
| ORM 与模型 | 数据表字段、索引和模型结构通过 `#[Table]`、`#[Col]`、`#[Index]` 等属性声明 |
| 服务层 | Controller 负责请求边界，复杂业务进入 Service，Model 负责数据表达和 ORM 查询 |
| 主题系统 | 前后台模板、Block、Taglib、Widget、Hook 和静态资源按 area 与模块边界组织 |
| 国际化 | 用户可见文本默认走 i18n；PHP 使用 `__()`，模板和标签按项目规则使用翻译机制 |
| WLS 运行时 | 支持传统 FPM 部署，也支持框架自带的 WLS 长运行服务器；WLS 负责 Master/Worker、Dispatcher、Gateway、HTTP Redirect、Maintenance、IPC 控制面、热重载、SSL 动态加载和进程治理 |
| 共享状态服务 | WLS 提供 Session 共享服务、Memory 共享服务、连接池和长生命周期状态清理能力，避免 Worker 跨请求污染 |
| 异步与计划任务 | `Weline_Queue` 提供数据库队列、任务状态、重试和批处理；`Weline_Cron` 与 WLS Maintenance 能承载定时清理、证书续期、健康巡检等后台任务 |
| 邮局与发信 | `Weline_Mail` 管理自建企业邮局、邮箱域名、账号、DNS 和服务状态；`Weline_Smtp` 提供统一发信入口，支持外部 SMTP 和自建邮局账号 |
| 安全与观测 | WLS 提供攻击信号监控、运行指标、错误扫描、日志聚合、实例状态和后台监控入口 |
| CLI | `bin/w` 提供安装、升级、缓存、模块、迁移、路由、WLS、队列、邮局、SMTP、证书和开发辅助命令 |

### WLS 服务族

WLS 不只是一个本地 HTTP 入口，而是框架的长运行服务编排层。开发者需要把它理解为一组可治理的服务：

| 服务 | 说明 |
|---|---|
| Master / Orchestrator | 管理实例配置、子进程生命周期、控制端口、热重载、重启和异常恢复 |
| Worker | 执行业务请求、模板渲染、Controller、API、SSE、WebSocket/WebRTC 信令等长连接协议匹配 |
| Dispatcher | Windows 等场景下接收请求并分发到 Worker，维护路由缓存和负载分配 |
| Gateway | WLS 网关与服务发现入口，面向多实例、多服务接入 |
| HTTP Redirect | 独立处理 HTTP 到 HTTPS 或域名策略相关跳转 |
| Session / Memory | 提供共享 Session、共享内存缓存、TTL、跨 Worker 状态访问和状态 Facade |
| Maintenance | 执行维护态检查、清理、指标采集、证书自动续期和后台巡检任务 |
| IPC Control Plane | 通过控制消息处理 `status`、`reload`、`restart`、`stop`、`ssl_cert_reload` 等运行时操作 |

### 基础服务模块

| 模块 | 说明 |
|---|---|
| `Weline_Mail` | 企业邮局管理模块，管理邮箱域名、账号、DNS 检测、服务状态和底层邮件服务环境 |
| `Weline_Smtp` | 统一发信模块，业务可通过 `w_query('smtp', 'send', ...)` 使用外部 SMTP 或自建邮局账号发信 |
| `Weline_Queue` | 消息队列模块，支持异步任务、批量任务、任务状态、重试、监控和日志 |
| `Weline_Cron` | 计划任务模块，配合 WLS Maintenance 或系统调度承载周期性任务 |

更多能力、产品形态和业务场景介绍请访问 [WelineFramework 官网](https://www.aiweline.com)。

## 仓库结构

| 路径 | 说明 |
|---|---|
| `app/code/` | 核心模块和业务模块源码 |
| `app/design/` | 前后台主题、模板和静态资源 |
| `app/etc/` | 项目配置，例如 `env.php` |
| `bin/` | `bin/w` CLI、安装脚本和引导脚本 |
| `docs/` | 项目级文档、命令文档、部署文档、API 文档和多语言 README |
| `dev/ai/` | AI 工程代理入口、全局约束、技能索引、架构图和任务记录 |
| `generated/` | 框架生成产物，不应手工修改 |
| `pub/` | Web 入口和公开静态资源 |
| `var/` | 缓存、日志、会话和运行时状态 |
| `extend/server/` | 项目本地运行时组件，例如本地 PHP 或数据库组件 |
| `vendor/` | Composer 依赖 |

模块内部文档保留在各模块自己的 `doc/` 目录中。处理具体模块前，优先阅读模块文档，再进入源码。

## 环境要求

一键安装只要求本机已有 Git，脚本会准备项目运行环境。纯净安装需要自行准备：

- PHP `^8.4`
- Composer `^2.7`
- MySQL / MariaDB / PostgreSQL 等可用数据库环境
- Nginx / Apache 或 Weline 框架内置服务器（WLS）

请以当前用户执行安装命令，不要使用 `sudo` 直接启动一键安装。Linux 以 root 运行时，脚本会创建并切换到 `weline` 用户执行克隆和安装。

## 安装方式

### 一键安装

一键安装适合快速获得可运行的本地环境。脚本会克隆仓库、准备 `extend/server` 下的运行时、安装依赖并执行初始化流程。

Linux / macOS / Git Bash：

```bash
curl -fsSL https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.sh | bash -s --
```

Windows PowerShell：

```powershell
$f="$env:TEMP\weline-bootstrap.ps1"; irm 'https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.ps1' -OutFile $f; & $f
```

常用参数：

| 参数 | 说明 |
|---|---|
| `-b <分支>` | 指定分支，例如 `-b dev` |
| `-y` | 自动确认安装过程中的提示 |
| `-f` | 强制重新安装，会清空已有数据 |
| `--path-only` | 只写入 PATH，不重新安装组件 |
| `php` / `pgsql` / `mysql` | 指定安装组件 |

示例：安装 `dev` 分支并自动确认。

```bash
curl -fsSL https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.sh | bash -s -- -b dev -y
```

```powershell
$f="$env:TEMP\weline-bootstrap.ps1"; irm 'https://gitee.com/aiweline/WelineFramework/raw/master/bin/bootstrap.ps1' -OutFile $f; & $f -b dev -y
```

### 纯净安装

纯净安装只获取源码和 Composer 依赖，不安装 `extend/server` 下的 PHP 或项目本地数据库。

Git：

```bash
git clone https://gitee.com/aiweline/WelineFramework.git weline
cd weline
composer install
php bin/w command:upgrade
```

指定分支：

```bash
git clone -b dev https://gitee.com/aiweline/WelineFramework.git weline
cd weline
composer install
php bin/w command:upgrade
```

Composer：

```bash
composer create-project aiweline/weline-framework weline --prefer-dist
cd weline
php bin/w command:upgrade
```

首次安装数据库必须通过命令行初始化。查看当前版本支持的安装参数模板：

```bash
php bin/w system:install:sample
```

MySQL 初始化示例：

```bash
php bin/w system:install \
  --db-type=mysql \
  --db-hostname=127.0.0.1 \
  --db-database=weline \
  --db-username=weline \
  --db-password=weline \
  --db-charset=utf8mb4 \
  --db-collate=utf8mb4_general_ci \
  --sandbox_db-type=mysql \
  --sandbox_db-hostname=127.0.0.1 \
  --sandbox_db-database=sandbox_weline \
  --sandbox_db-username=sandbox_weline \
  --sandbox_db-password=sandbox_weline \
  --sandbox_db-charset=utf8mb4 \
  --sandbox_db-collate=utf8mb4_general_ci
```

启动框架内置服务器（WLS）：

```bash
php bin/w server:start
```

## 开发流程

常规开发建议按以下顺序进行：

1. 阅读项目入口文档、模块文档和命中的技能文件。
2. 确认修改所属模块、area、入口、调用链、数据结构、权限边界和用户可见行为。
3. 在模块边界内实现最小必要改动，避免跨模块直接引用内部实现。
4. 新增或调整 Controller 后执行 `php bin/w setup:upgrade --route`。
5. 新增或调整模型字段、索引、模块配置后执行 `php bin/w setup:upgrade`。
6. 用户可见文本同步处理 i18n。
7. 执行最接近改动的验证命令；页面、路由、表单和交互类改动需要真实浏览器冒烟。
8. 更新受影响的模块 `doc/` 或项目文档。

## 常用命令

| 命令 | 说明 |
|---|---|
| `php bin/w` | 查看可用命令 |
| `php bin/w command:upgrade` | 刷新命令注册 |
| `php bin/w setup:upgrade` | 升级模块、模型结构和系统配置 |
| `php bin/w setup:upgrade --route` | 控制器或路由变更后刷新路由 |
| `php bin/w server:start` | 启动框架内置服务器（WLS） |
| `php bin/w server:status --all` | 查看 WLS 实例和进程状态 |
| `php bin/w server:reload` | 热重载业务代码和 Worker |
| `php bin/w server:doctor` | 查看 WLS 运行时诊断信息 |
| `php bin/w cache:clear` | 清理缓存 |
| `php bin/w module:status` | 查看模块状态 |
| `php bin/w query:help <provider>` | 查看 Query Provider 契约 |
| `php bin/w db:migrate:status --module=<Module>` | 查看数据库迁移状态 |
| `php bin/w queue:status` | 查看队列任务状态 |
| `php bin/w queue:collect` | 收集自动执行队列任务 |
| `php bin/w mail:env:check` | 检查企业邮局运行环境 |
| `php bin/w mail:service:status` | 查看邮局底层服务状态 |
| `php bin/w mail:dns:check <domain> <host>` | 检查邮箱域名 DNS 配置 |
| `php bin/w http:request /` | 使用框架命令发起本地 HTTP 请求 |
| `php bin/w http:request admin -b` | 请求后台入口并应用 backend 上下文 |

## 开发约束

这些约束是为了减少不可追踪的生成产物、隐式耦合和运行时差异：

- 不直接修改 `generated/`。
- 不手写或新增 `routes.xml`。
- 新 Controller 或路由变更后执行 `php bin/w setup:upgrade --route`。
- 表结构字段和索引用 Model 属性 `#[Col]`、`#[Index]`、`#[Table]` 声明，再执行 `php bin/w setup:upgrade`。
- ORM 链必须以 `fetch()` 或 `fetchArray()` 执行。
- 业务代码不写数据库方言 SQL；方言只放在框架适配器层。
- 用户可见文本默认支持国际化。
- `.phtml` 不加入 `declare(strict_types=1);`。
- WLS 代码中不使用 `sleep`、`die`、`exit`。
- AI 测试不使用默认 WLS 端口 `9501`；使用 `9502+` 独立测试实例，并在测试结束后停止。

更多 AI 工程代理约束见 [AI-ENTRY.md](./AI-ENTRY.md)、[AGENTS.md](./AGENTS.md) 和 [CLAUDE.md](./CLAUDE.md)。

## 安装脚本

| 脚本 | 用途 |
|---|---|
| `bin/bootstrap.sh` | Linux / macOS / Git Bash 一键引导 |
| `bin/bootstrap.ps1` | Windows PowerShell 一键引导 |
| `bin/install` | Linux / macOS / Git Bash 项目内安装入口 |
| `bin/install.sh` | Linux / macOS 安装逻辑，一般由 `bin/install` 调用 |
| `bin/install.bat` | Windows CMD / PowerShell 项目内安装入口 |

每个项目会使用自己的 `extend/server/pgsql/data`。首次安装项目本地 PostgreSQL 时，脚本会按项目路径选择稳定高位端口，并同步到 `weline.env`、`postgresql.conf` 和 `app/etc/env.php`。已有 `env.php` 指向外部数据库时，脚本会跳过项目本地 PostgreSQL 操作。

## 文档地图

| 文档 | 用途 |
|---|---|
| [docs/README.md](./docs/README.md) | 项目文档索引 |
| [docs/weline/README.md](./docs/weline/README.md) | Weline 架构文档目录 |
| [docs/weline/架构总览.md](./docs/weline/架构总览.md) | 框架架构总览 |
| [docs/开发文档.md](./docs/开发文档.md) | 开发文档 |
| [docs/部署文档.md](./docs/部署文档.md) | 部署文档 |
| [docs/fix/README.md](./docs/fix/README.md) | 问题修复与排查 |
| [docs/commands/](./docs/commands/) | 命令文档 |
| [docs/api/](./docs/api/) | API 文档 |
| [docs/部署/](./docs/部署/) | 部署专题 |
| [app/code/Weline/Server/doc/README.md](./app/code/Weline/Server/doc/README.md) | WLS 文档导航 |
| [app/code/Weline/Server/doc/WLS-Gateway使用指南.md](./app/code/Weline/Server/doc/WLS-Gateway使用指南.md) | WLS Gateway 使用 |
| [app/code/Weline/Server/doc/WLS_Session共享服务架构.md](./app/code/Weline/Server/doc/WLS_Session共享服务架构.md) | WLS Session 共享服务 |
| [app/code/Weline/Mail/doc/README.md](./app/code/Weline/Mail/doc/README.md) | 企业邮局模块 |
| [app/code/Weline/Smtp/README.md](./app/code/Weline/Smtp/README.md) | SMTP 统一发信模块 |
| [app/code/Weline/Queue/doc/README.md](./app/code/Weline/Queue/doc/README.md) | 消息队列模块 |
| [app/code/Weline/Database/doc/开发/数据库迁移系统开发文档.md](./app/code/Weline/Database/doc/开发/数据库迁移系统开发文档.md) | 数据库迁移开发 |
| [app/code/Weline/Database/doc/用户/数据库迁移系统使用手册.md](./app/code/Weline/Database/doc/用户/数据库迁移系统使用手册.md) | 数据库迁移使用 |
| [app/code/Weline/Ai/doc/README.md](./app/code/Weline/Ai/doc/README.md) | AI 模块文档 |

## 贡献

1. Fork 本仓库。
2. 新建功能分支。
3. 按模块边界完成开发与验证。
4. 更新受影响的模块文档或项目文档。
5. 提交 Pull Request。

提交前请确认没有包含密钥、token、`.env`、生产连接串或本地敏感配置。

## 许可证

本仓库许可证以 [composer.json](./composer.json) 中的 `license` 字段为准。

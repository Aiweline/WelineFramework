# Weline_Deploy 模块

## 简介

Weline_Deploy 模块提供了基于 Git 的自动部署、发布系统与版本探测功能。

## 功能特性

- Git 仓库自动拉取代码（`deploy:build`）
- 完整发布流水线：Git + 后置命令 + 版本戳 + reload（`deploy:release`）
- 分支发布（版本 = commit 短 SHA）与 Tag 发布（版本 = tag 名）
- Webhook 自动触发部署（支持 Gitee / GitHub / 通用平台）
- 运行时版本探测（`GET <随机 Webhook 路径>/version`）
- 发布历史记录与后台管理界面
- CI 门禁等待版本生效（`deploy:release:wait`）
- 部署前自动备份、强制更新模式
- Cloudflare 缓存清理

## 安装

模块已包含在项目中，首次使用更新命令列表：

```bash
php bin/w command:upgrade
```

## 快速开始

### 1. 后台配置

进入 `系统管理 > 系统维护 > 部署配置`，填写项目仓库和 Webhook 信息。

### 2. 配置 Webhook 访问密码

生成密钥并写入后台，同时输出 curl 测试命令：

```bash
php bin/w deploy:webhook:setup --base-url=https://你的域名
```

刷新（轮换）访问密码（须同步更新 Git 平台 Secret）：

```bash
php bin/w deploy:webhook:setup --force -y --base-url=https://你的域名
```

在 Git 平台添加 Webhook：URL 使用命令输出的 `https://你的域名/~wh~...` 随机地址，Secret/密码与后台「Webhook 密钥」一致。

详见 [`doc/webhook-secret.md`](doc/webhook-secret.md) 与 [`doc/backend-config.md`](doc/backend-config.md)。

### 3. 执行发布

```bash
# 完整发布（推荐）
php bin/w deploy:release

# 仅 Git 拉取（轻量，不含版本戳）
php bin/w deploy:build
```

## 命令参考

| 命令 | 说明 |
|------|------|
| `php bin/w deploy:build` | 仅 Git 拉取代码 |
| `php bin/w deploy:build -b develop` | 指定分支拉取 |
| `php bin/w deploy:build --force` | 强制拉取（丢弃本地修改） |
| `php bin/w deploy:release` | 完整发布：Git + 后置命令 + 版本戳 + reload |
| `php bin/w deploy:release -r refs/tags/v1.0.0` | Tag 发布 |
| `php bin/w deploy:release:status` | 查看当前部署版本 |
| `php bin/w deploy:release:wait --expect=v1.0.0` | CI 门禁：等待版本生效 |
| `php bin/w deploy:webhook:setup --base-url=https://域名` | 生成/查看 Webhook 访问密码、随机公网路径并输出 curl |
| `php bin/w deploy:webhook:setup --force -y --url=...` | 刷新访问密码并覆盖后台配置 |

## 版本策略

| 场景 | deploy_version 来源 |
|------|---------------------|
| 分支 push | Git commit 短 SHA（如 `a3f5c2d`） |
| Tag push | tag 名（如 `v2.4.1`） |

## 触发模式

后台配置「部署触发方式」决定 Webhook 何时触发部署：

| 模式 | 含义 |
|------|------|
| `仅分支 Push` | 只有分支推送触发，tag 推送忽略 |
| `仅 Tag Push` | 只有 tag 推送触发，分支推送忽略 |
| `分支 + Tag 都生效` | 两者都触发（需明确选择） |

默认模式为 `仅 Tag Push`，分支 push 会返回 `trigger_mode_tag_only`，只有明确选择分支模式或“两者都生效”时才响应分支发布。

## 版本探测

```bash
# 最小信息（无 token）
curl -s 'https://你的域名/~wh~.../version'

# 详细信息（需配置「发布探测 Token」）
curl -s 'https://你的域名/~wh~.../version?token=xxx'

# 健康检查（含版本）
curl -s 'https://你的域名/~wh~...?health=1'
```

## 目录结构

```
app/code/Weline/Deploy/
├── register.php                          # 模块注册
├── composer.json                         # 模块依赖
├── README.md                             # 模块概览（本文件）
├── 使用说明.md                            # 详细使用说明
├── doc/                                  # 文档目录
│   ├── README.md                         # 文档索引
│   ├── backend-config.md                 # 后台配置指南
│   ├── webhook-secret.md                 # Webhook 访问密码与轮换
│   ├── github-webhook.md                 # GitHub Webhook 配置
│   └── gitee-webhook.md                  # Gitee Webhook 配置
├── Console/
│   └── Deploy/
│       ├── Build.php                     # deploy:build 命令
│       └── Release/
│           ├── Release.php               # deploy:release 命令
│           ├── Status.php                # deploy:release:status
│           └── Wait.php                  # deploy:release:wait
├── Controller/
│   ├── Webhook.php                       # Webhook 入口（前端）
│   ├── Version.php                       # 版本探测端点
│   ├── Api/Webhook.php                   # Webhook 入口（REST）
│   └── Backend/
│       ├── Config.php                    # 后台配置页
│       └── Release.php                   # 后台发布历史页
├── Model/
│   └── DeployRelease.php                 # 发布历史 ORM 模型
├── Observer/
│   └── ReleaseAfter.php                  # 发布完成后事件观察者
├── Service/
│   ├── DeployConfigService.php           # 配置服务
│   ├── DeployOrchestratorService.php     # 统一部署编排
│   ├── DeployReleaseRuntimeService.php   # 运行时版本戳
│   ├── DeployReleaseHistoryService.php   # 发布历史 CRUD
│   ├── DeployGitMetadataService.php      # Git 元数据读取
│   └── DeployWebhookRefResolver.php      # Webhook ref 解析
├── Setup/
│   └── Install.php                       # 建表脚本
├── etc/
│   ├── env.php                           # 路由配置
│   ├── event.xml                         # 事件声明
│   └── backend/menu.xml                  # 后台菜单
├── i18n/
│   ├── zh_Hans_CN.csv                    # 中文翻译
│   └── en_US.csv                         # 英文翻译
└── view/templates/Backend/
    ├── Config/index.phtml                # 后台配置模板
    └── Release/index.phtml               # 发布历史模板
```

## 文档

| 文档 | 说明 |
|------|------|
| [`doc/webhook-secret.md`](doc/webhook-secret.md) | Webhook 访问密码：`deploy:webhook:setup` 生成与轮换 |
| [`doc/backend-config.md`](doc/backend-config.md) | 后台配置、Nginx + WLS、触发模式、Cloudflare |
| [`doc/github-webhook.md`](doc/github-webhook.md) | GitHub Webhook 填表步骤 |
| [`doc/gitee-webhook.md`](doc/gitee-webhook.md) | Gitee Webhook 填表步骤 |
| [`使用说明.md`](使用说明.md) | 详细使用说明与故障排查 |

## 许可证

MIT License

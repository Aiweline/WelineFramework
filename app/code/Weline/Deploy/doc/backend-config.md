# 后台部署配置指南

本文说明 `Weline_Deploy` 后台配置页的使用方式。后台配置保存后，会优先于 `dev/deploy/.config`、`.env` 和 `app/etc/env.php` 中的部署相关配置。

## 1. 后台入口

菜单位置：

```text
系统管理 > 系统维护 > 部署配置
```

对应后台路由：

```text
deploy/backend/config
```

首次上线该菜单和控制器后，需要在安全环境执行：

```bash
php bin/w setup:upgrade --route
```

不要在有未提交代码的开发工作区里触发部署或 Webhook 测试。

## 2. 配置优先级

部署配置按以下优先级读取：

1. 后台 `系统管理 > 系统维护 > 部署配置`
2. `dev/deploy/.config`
3. 项目根目录 `.env`
4. `app/etc/env.php`
5. 代码默认值

后台字段留空时，不会覆盖下层配置；密钥类字段留空保存时表示保留已有后台密钥。

## 3. 项目仓库

项目仓库用于 Webhook 部署和 `php bin/w deploy:build`。

建议填写：

- `项目仓库地址`：项目代码仓库，例如 `https://gitee.com/org/project.git`
- `项目分支`：生产部署分支，例如 `master` 或 `main`
- `Git Remote 名称`：通常是 `origin`
- `项目仓库用户名`：HTTPS token 方式可填 `git`、`oauth2` 或平台用户名
- `项目仓库 Token`：私有仓库拉取 token，留空保存时不修改已有后台 token

安全建议：

- 优先使用服务器 SSH key 或最小权限 token。
- 不要把 token 写进可提交的 `.config`。
- 生产部署目录应保持干净，不应有手工修改的已跟踪文件。

## 4. 核心仓库

核心仓库用于 `php bin/w deploy:update:core`。

建议填写：

- `核心仓库地址`：框架核心仓库，例如 `https://gitee.com/aiweline/WelineFramework.git`
- `核心默认分支`：例如 `master`
- `核心仓库用户名`：私有核心仓库需要时填写
- `核心仓库 Token`：私有核心仓库 token，留空保存时不修改已有后台 token

后台核心仓库配置会覆盖 `app/etc/env.php` 的 `core_update` 和 `.env` 的 `CORE_UPDATE_*`。

## 5. Webhook

Webhook 配置用于 `dev/deploy/webhook.php` 和 `dev/deploy/webhook.sh listen`。

建议填写：

- `Webhook 密钥`：Gitee/GitHub Webhook Secret
- `Webhook 分支`：只允许该分支触发部署，例如 `master`
- `请求路径`：通常是 `/deploy`
- `Bash 命令`：通常是 `bash`，服务器路径特殊时可填 `/bin/bash`
- `监听地址` / `监听端口`：仅常驻监听模式使用，PHP 入口方式一般不依赖它们

推荐触发方式：

```text
Git 平台 Webhook -> Nginx/Caddy -> dev/deploy/webhook.php -> webhook.sh deploy
```

`webhook.php` 会先读取后台配置；如果后台或数据库不可用，再回退 `dev/deploy/.config`。

## 6. 部署行为

关键选项：

- `Git 更新模式`
  - `reset`：fetch 后 reset 到目标分支，部署结果更确定。
  - `pull_ff_only`：只允许快进，遇到分叉失败。
- `允许覆盖本地改动`
  - 默认关闭。开启会允许 `reset --hard` 覆盖服务器本地已跟踪改动。
- `允许切换分支`
  - 默认关闭。当前分支和目标分支不一致时，开启后才允许切换。
- `更新 Submodule`
  - 开启后部署会执行 submodule 更新。
- `运行 Composer`
  - 开启后部署会执行 `Composer 命令`。
- `部署后命令`
  - 可写 `php bin/w setup:upgrade --route && php bin/w server:reload -r` 这类后置动作。

## 7. Cloudflare

Cloudflare 默认关闭。

只有同时满足以下条件才会清理缓存：

- `启用缓存清理` 已打开
- `Cloudflare Zone ID` 已填写
- `Cloudflare API Token` 已填写

Token 至少需要对应 Zone 的 Cache Purge 权限。

## 8. 关联指南

- `app/code/Weline/Deploy/doc/gitee-webhook.md`：Gitee Webhook 配置
- `app/code/Weline/Deploy/doc/github-webhook.md`：GitHub Webhook 配置
- `dev/deploy/.config.exsample`：服务器文件配置示例

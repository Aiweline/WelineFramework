---
name: deploy-release-system
description: >-
  Weline_Deploy：随机 Webhook 路径（~wh~）、ModuleRouter、webhook_secret、
  deploy:webhook:setup、deploy:release、WLS+Nginx。
---

# Weline_Deploy 部署发布系统

命中关键词：`Weline_Deploy`、`deploy:release`、`deploy:webhook:setup`、`webhook_secret`、`~wh~`、`DeployWebhookRouteService`、`Controller/Router.php`、Gitee/GitHub Webhook。

## 核心原则

1. **公网 Webhook URL 为随机路径**：`https://<域名>/~wh~<32位hex>`，由 `deploy:webhook:setup` 生成；**禁止**使用 `/deploy` 等常见路径。
2. **路由匹配走模块 Router**：`app/code/Weline/Deploy/Controller/Router.php` 实现 `RouterInterface`，在 `Weline_ModuleRouter` 阶段用 `~wh~` 前缀短路后 `hash_equals`，将 `$path` 改写为内部 `deploy/webhook/deploy`（非 Nginx 别名、非独立 PHP 入口）。
3. **访问密码**：后台 `webhook_secret` = Git 平台 Secret/密码。
4. **架构**：`Git → Nginx → WLS → ModuleRouter(Router.php) → deploy/webhook/deploy → webhook.sh`。
5. 分仓口令「分仓」→ `CI发布工程师-分仓发布`。

## ModuleRouter 与本模块（必读）

| 组件 | 路径 | 作用 |
|------|------|------|
| 公网路径配置 | `DeployConfigService` → `webhook_path` | 存 `~wh~…` 随机串 |
| 路径服务 | `Service/DeployWebhookRouteService.php` | 生成路径、缓存、`MARKER=~wh~`、失效缓存 |
| URL 匹配 | `Controller/Router.php` | `process()` 改写 `$path` 到内部路由 |
| HTTP 处理 | `Controller/Webhook.php` | 鉴权、触发 `webhook.sh` |
| 版本探测 | 公网 `~wh~…/version` → 内部 `deploy/version` | 同 Router 改写 |

**修改随机路径后**须 `DeployWebhookRouteService::clearCache()`（setup 保存时已调用）。  
**不要**为 Webhook 单独加 Nginx location 或 `routes.xml`。  
通用 ModuleRouter 约定见 `框架核心工程师-路由事件与扩展`。

## CLI

```bash
php bin/w command:upgrade -m Weline_Deploy

# 首次：随机路径 + 密钥
php bin/w deploy:webhook:setup --base-url=https://www.example.com

# 轮换密钥 + 路径
php bin/w deploy:webhook:setup --force -y --base-url=https://www.example.com

# 仅轮换路径
php bin/w deploy:webhook:setup --rotate-path -y --base-url=https://www.example.com
```

输出含：公网路径、`webhook_secret`、完整 Webhook URL、版本 URL、curl 示例。

## 鉴权

- `Authorization: Bearer <webhook_secret>`（推荐）
- `X-Gitee-Token`、`X-Hub-Signature-256`、`?token=`
- `GET <webhook_url>?health=1` 无需密码

## 生产 Nginx

全站反代 WLS 即可；公网路径由 ModuleRouter 处理，无需为 `~wh~` 单独配 location。

```nginx
location / {
    proxy_pass http://127.0.0.1:9501;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

## 触发模式

`deploy_trigger_mode`：`branch` | `tag` | `both`。过滤：`webhook_branch`、`webhook_tag_prefix`。

## 参考资料

| 文件 | 用途 |
|------|------|
| `doc/webhook-secret.md` | 密码与路径轮换 |
| `doc/backend-config.md` | 后台与 Nginx |
| `Controller/Router.php` | ModuleRouter 匹配 |
| `Service/DeployWebhookRouteService.php` | 路径生成与缓存 |
| `框架核心工程师-路由事件与扩展` | 通用 Router 技能 |

## 排错

| 现象 | 检查 |
|------|------|
| 404 公网路径 | `webhook_path` 是否 `~wh~` 开头；Router.php 是否生效；缓存是否清理 |
| 403 invalid token | `webhook_secret` 与 Git 平台不一致 |
| WEBHOOK_SECRET is empty | 未执行 setup |
| 202 skipped | 触发模式/分支过滤 |
| 500 webhook.sh not found | `dev/deploy/webhook.sh` |

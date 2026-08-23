# Weline_Deploy 文档索引

## 配置文档

| 文档 | 说明 |
|------|------|
| [backend-config.md](backend-config.md) | **主配置指南**：后台部署、Nginx + WLS、触发模式、发布命令 |
| [webhook-secret.md](webhook-secret.md) | **Webhook 访问密码**：`webhook_secret` 配置与 `deploy:webhook:setup` 轮换命令 |
| [wls-panel-project-webhook.md](wls-panel-project-webhook.md) | **WLS Panel 项目级 Webhook**：项目上下文、Profile 覆盖与 `deploy_root` 执行目录 |

## Webhook 平台配置

| 文档 | 说明 |
|------|------|
| [gitee-webhook.md](gitee-webhook.md) | Gitee：管理 → WebHooks → 添加 |
| [github-webhook.md](github-webhook.md) | GitHub：Settings → Webhooks → Add webhook |

## 模块根目录文档

| 文档 | 说明 |
|------|------|
| [README.md](../README.md) | 模块概览、命令参考 |
| [使用说明.md](../使用说明.md) | deploy:build / deploy:release、故障排查 |

## 快速导航

- **AI/MCP 只读部署计划** → `php bin/w deploy:plan --json --operation=<config|preflight|release> --target=<local|staging|production>`
- **生成或刷新 Webhook 访问密码** → `php bin/w deploy:webhook:setup`（见 [webhook-secret.md](webhook-secret.md)）
- **配置 Gitee / GitHub Webhook** → [backend-config.md](backend-config.md) 第 5 节
- **配置 Tag 发布** → [backend-config.md](backend-config.md) 第 7 节
- **WLS Panel 项目发布预检** → WLS Panel 进入 Deploy 插件页后查看「发布预检」，它只检查项目 Profile、仓库、触发模式、Webhook 和命令白名单，不执行真实发布
- **WLS Panel Webhook 回放预检** → 同一 Deploy 插件页输入 `refs/tags/...` 或 `refs/heads/...`，只判断策略会 `ready` 还是 `skipped`，不触发真实发布
- **WLS Panel 手动发布** → 同一 Deploy 插件页先 `Build Plan` 预览，再勾选确认后通过已注册的 `manual-plan-run` POST 入口执行 `Run Release`；服务端会重新检查 Profile、preflight 与 tag/branch 策略
- **WLS Panel 项目回滚** → 在项目 Profile 保存 `rollback_ref` 后，同一 Deploy 插件页会显示受保护的 `Run Rollback`；执行时只读取已保存 Profile，要求确认框、非 danger 预检和有效回滚 ref，然后在项目 `deploy_root` 执行真实 Git 回滚
- **WLS Panel 项目级真实 Webhook** → 真实 `~wh~` webhook 可通过 query 或 payload 传入 `profile_key` / `project_id` / `domain` / `project_type`，命中项目 Profile 后按该项目 `deploy_root` 发布
- **查看当前版本** → `php bin/w deploy:release:status` 或 `curl https://域名/~wh~.../version`；WLS Panel 子项目可追加 `project_id` / `domain` 等上下文读取项目 `deploy_root/var/deploy/current.json`
- **CI 等待部署完成** → `php bin/w deploy:release:wait --expect=v1.0.0`
- **发布历史** → 后台 `系统管理 > 系统维护 > 发布历史`

## 模块边界

- Webhook 路由缓存只能通过 `Weline\ModuleRouter\Api\RouteCache` 失效；Deploy 不得引用 ModuleRouter Observer。
- 网站 URL 候选只通过可选的 `Weline\Websites\Api\DefaultWebsiteUrl` 读取；未安装 Websites 时返回空候选，不产生类加载错误。
- WLS 面板扩展继续通过 `Integration/Server` Provider 注册，Deploy 不反向读取 Server 内部实现。

## 机器可读只读计划

`deploy:plan` 是 `Weline_Ai` MCP 可使用的唯一部署桥接。它输出 `deploy-machine-plan.v1` JSON，但绝不保存配置、写仓库或调用发布编排器。

| 操作 | 用途 | 可能状态 |
|---|---|---|
| `config` | 按固定顺序返回缺少的仓库、目录、Webhook 和基础 URL 配置 | `ready` / `needs_configuration` |
| `preflight` | 只读检查目录、命令白名单和 Webhook health | `ready` / `needs_configuration` / `blocked` |
| `release` | 为用户明确选择的 `commit` 或 `tag` 生成发布参数摘要 | `ready` / `blocked` |

`local` 固定返回 `not_applicable`。预发或生产计划中的 `release_executed` 与 `orchestrator_called` 始终为 `false`；`ready` 仅表示计划可供审查，不等于已获得真实发布授权。

```bash
php bin/w deploy:plan --json --operation=preflight --target=local
php bin/w deploy:plan --json --operation=release --target=production \
  --ref-type=tag --ref=v1.2.3 --base-url=https://example.com
```

输出仅包含允许的配置摘要，不包含 Token、Webhook secret、密码、私钥、用户名或带凭据的仓库 URL。

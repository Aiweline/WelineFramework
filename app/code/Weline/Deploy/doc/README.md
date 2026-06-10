# Weline_Deploy 文档索引

## 配置文档

| 文档 | 说明 |
|------|------|
| [backend-config.md](backend-config.md) | **主配置指南**：后台部署、Nginx + WLS、触发模式、发布命令 |
| [webhook-secret.md](webhook-secret.md) | **Webhook 访问密码**：`webhook_secret` 配置与 `deploy:webhook:setup` 轮换命令 |

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

- **生成或刷新 Webhook 访问密码** → `php bin/w deploy:webhook:setup`（见 [webhook-secret.md](webhook-secret.md)）
- **配置 Gitee / GitHub Webhook** → [backend-config.md](backend-config.md) 第 5 节
- **配置 Tag 发布** → [backend-config.md](backend-config.md) 第 7 节
- **查看当前版本** → `php bin/w deploy:release:status` 或 `curl https://域名/deploy/version`
- **CI 等待部署完成** → `php bin/w deploy:release:wait --expect=v1.0.0`
- **发布历史** → 后台 `系统管理 > 系统维护 > 发布历史`

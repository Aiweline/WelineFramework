# Weline_Deploy 文档索引

## 配置文档

| 文档 | 说明 |
|------|------|
| [backend-config.md](backend-config.md) | **主配置指南**：Webhook 全平台引导（Gitee / GitHub / 通用）、部署行为配置、Tag 发布开关、探测 Token、Cloudflare 清理 |

## Webhook 平台配置

| 文档 | 说明 |
|------|------|
| [github-webhook.md](github-webhook.md) | GitHub Webhook 配置步骤：Settings → Webhooks → Add webhook |
| [gitee-webhook.md](gitee-webhook.md) | Gitee Webhook 配置步骤：仓库管理 → WebHooks → 添加 |

## 模块根目录文档

| 文档 | 说明 |
|------|------|
| [README.md](../README.md) | 模块概览、命令参考、版本策略、版本探测、目录结构 |
| [使用说明.md](../使用说明.md) | deploy:build 详细使用说明、故障排查、定时任务、钩子集成 |

## 快速导航

- **我要配置 Webhook** → [`backend-config.md](backend-config.md) 的「Webhook 配置引导」章节
- **我要配置 Tag 发布** → [`backend-config.md](backend-config.md) 的「Tag 发布配置」章节
- **我要查看当前版本** → `php bin/w deploy:release:status` 或 `curl https://域名/deploy/version`
- **我要 CI 等待部署完成** → `php bin/w deploy:release:wait --expect=v1.0.0`
- **我要查看发布历史** → 后台菜单 `系统管理 > 系统维护 > 发布历史`

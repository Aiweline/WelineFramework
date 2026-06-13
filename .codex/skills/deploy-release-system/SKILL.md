---
name: deploy-release-system
description: >-
  Weline_Deploy — random ~wh~ Webhook URL via module Controller/Router.php (ModuleRouter),
  webhook_secret, deploy:webhook:setup, deploy:release, WLS+Nginx.
---

# Deploy Release System

Canonical: `dev/ai/skills/CI发布工程师-部署发布系统/SKILL.md`  
Module Router pattern: `dev/ai/skills/框架核心工程师-路由事件与扩展/SKILL.md`

## Essentials

1. **Public URL**: random `https://<host>/~wh~<hex>` from `deploy:webhook:setup` — not `/deploy`.
2. **Routing**: `app/code/Weline/Deploy/Controller/Router.php` implements `RouterInterface`; `Weline_ModuleRouter` calls `process()` → rewrites `$path` to internal `deploy/webhook/deploy`. Use `~wh~` prefix for fast bail-out.
3. **Secret**: `webhook_secret` in backend = Git platform Secret.
4. **Version probe**: `<webhook_path>/version` → internal `deploy/version`.

## Commands

```bash
php bin/w deploy:webhook:setup --base-url=https://example.com
php bin/w deploy:webhook:setup --force -y --base-url=https://example.com
php bin/w deploy:webhook:setup --rotate-path -y --base-url=https://example.com
```

## When adding similar custom URLs in other modules

- Add `Controller/Router.php` + `RouterInterface::process()`
- Use a rare path prefix; early `return` if no match
- Rewrite `$path` to existing generated controller route
- Clear `ProcessUrlBefore::clearCache()` when config-driven paths change

Split-repo (`分仓`) → `CI发布工程师-分仓发布`.
Project core sync (`分项`) → `CI发布工程师-分项更新`.

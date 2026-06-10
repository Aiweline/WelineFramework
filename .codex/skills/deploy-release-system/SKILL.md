---
name: deploy-release-system
description: Use when configuring, operating, or troubleshooting the Weline_Deploy release system — including Webhook setup (Gitee/GitHub/generic), trigger mode (branch/tag/both), deploy:release CLI, version probe, release history, or post-deploy commands.
---

# Deploy Release System

Use this skill for any task involving `Weline_Deploy` release pipeline, Webhook deployment, version management, or deployment troubleshooting.

## Primary References

Load these files only as needed:

- `app/code/Weline/Deploy/README.md`: module overview, command reference, directory structure.
- `app/code/Weline/Deploy/使用说明.md`: detailed usage, troubleshooting, scheduled tasks.
- `app/code/Weline/Deploy/doc/README.md`: documentation index.
- `app/code/Weline/Deploy/doc/backend-config.md`: backend configuration guide with Webhook setup for all platforms.
- `app/code/Weline/Deploy/doc/gitee-webhook.md`: Gitee Webhook step-by-step.
- `app/code/Weline/Deploy/doc/github-webhook.md`: GitHub Webhook step-by-step.
- `app/code/Weline/Deploy/Service/DeployConfigService.php`: all configuration keys and defaults.
- `app/code/Weline/Deploy/Service/DeployWebhookRefResolver.php`: trigger mode logic.
- `app/code/Weline/Deploy/Service/DeployOrchestratorService.php`: release pipeline orchestration.
- `app/code/Weline/Deploy/Controller/Webhook.php`: Webhook endpoint implementation.

## Trigger Mode

The system supports three trigger modes configured via `deploy_trigger_mode` in backend config:

| Mode | Behavior |
|------|----------|
| `branch` | Only branch push triggers deployment. Tag push is ignored. Version = commit short SHA. |
| `tag` | Only tag push triggers deployment. Branch push is ignored. Version = tag name. |
| `both` | Both branch and tag push trigger deployment (default). |

Additional filters:
- `webhook_branch`: when set, only this branch name matches (branch/both mode).
- `webhook_tag_prefix`: when set, only tags with this prefix match (tag/both mode).

## Deployment Flow

### Webhook Trigger (Gitee / GitHub)

1. Git platform sends POST to `/deploy` with push event payload.
2. Controller verifies signature (Gitee HMAC-SHA256 / GitHub HMAC-SHA1 / Bearer token).
3. `DeployWebhookRefResolver` parses `ref` field, applies trigger mode + prefix/branch filters.
4. If matched, `webhook.sh` executes Git pull + post-deploy commands.
5. Response includes `deploy_version_hint` and `git_ref_type`.

### CLI Trigger

```bash
# Full release pipeline (Git + post-command + version stamp + reload)
php bin/w deploy:release

# Tag release
php bin/w deploy:release --ref=refs/tags/v1.0.0

# Check current version
php bin/w deploy:release:status

# CI gate: wait for version to be live
php bin/w deploy:release:wait --expect=v1.0.0 --timeout=120
```

### Version Probe

```bash
# Minimal (no token)
curl -s 'https://域名/deploy/version'

# Detailed (requires deploy_probe_token)
curl -s 'https://域名/deploy/version?token=xxx'

# Health check
curl -s 'https://域名/deploy?health=1'
```

## Gitee Webhook Setup

1. Go to repo → **管理** → **WebHooks** → **添加**.
2. URL: `https://your-domain/deploy`
3. Password: same as `webhook_secret` in backend config.
4. Trigger: select **Push Events** (and **Tag Push Events** if using `tag` or `both` mode).
5. If `deploy_trigger_mode = tag`, also select **Tag Push Events**.
6. Save and test with a push.

## GitHub Webhook Setup

1. Go to repo → **Settings** → **Webhooks** → **Add webhook**.
2. Payload URL: `https://your-domain/deploy`
3. Content type: `application/json`
4. Secret: same as `webhook_secret` in backend config.
5. Events: select **Just the push event** (covers both branch and tag push).
6. Save and test.

## Guardrails

- `webhook_secret` must not be empty; the controller rejects requests when it is.
- The Webhook endpoint returns 202 (not 200) when a push is skipped due to trigger mode filtering.
- `deploy:release` writes `var/deploy/current.json` for hot-path version lookup.
- `release_after` event dispatches after successful release; `ReleaseAfter` observer syncs `theme.static_version`.
- Backend ACL: `Weline_Deploy::deploy_config` and `Weline_Deploy::deploy_release`.
- The route prefix is `deploy`, not `weline_deploy`.
- Tag name becomes `deploy_version` directly; branch push uses commit short SHA.
- `deploy_probe_token` protects detailed version info; unauthenticated requests get minimal response.

## Troubleshooting

| Symptom | Check |
|---------|-------|
| Webhook returns 403 | `webhook_secret` mismatch between backend config and Git platform |
| Webhook returns 202 (skipped) | Trigger mode filtering: branch push when mode=tag, or tag push when mode=branch |
| Webhook returns 500 | `dev/deploy/webhook.sh` missing or script error; check `output_tail` in response |
| Version not updating | `var/deploy/current.json` not written; check `deploy:release:status` |
| Frontend version stale | CDN/Cloudflare cache; `release_after` event not firing |
| Tag deploy not working | `deploy_trigger_mode` not set to `tag` or `both`; or `webhook_tag_prefix` mismatch |

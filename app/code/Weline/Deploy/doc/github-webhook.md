# GitHub Webhook 配置指南

本文说明如何把 GitHub Webhook 接到 `Weline_Deploy` 部署入口，让服务器收到 push 事件后更新当前 Git 代码。

后台配置页说明见 `app/code/Weline/Deploy/doc/backend-config.md`。

## 1. 服务器配置

可以在后台 `系统管理 > 系统维护 > 部署配置` 中配置项目仓库、核心仓库和 Webhook 信息。后台配置保存后优先于 `dev/deploy/.config`。

如果后台暂时不可用，在服务器项目目录中编辑 `dev/deploy/.config`：

```bash
DEPLOY_ROOT='/www/wwwroot/weline'
GIT_REMOTE='origin'
GIT_BRANCH='main'
GIT_UPDATE_MODE='reset'
DEPLOY_FORCE_RESET='0'
DEPLOY_SWITCH_BRANCH='0'

WEBHOOK_HOST='127.0.0.1'
WEBHOOK_PORT='9097'
WEBHOOK_PATH='/deploy'
WEBHOOK_SECRET='请替换为强随机密钥'
WEBHOOK_BRANCH='main'

CLOUDFLARE_ENABLED='0'
```

关键配置：

- `DEPLOY_ROOT`：服务器上的项目根目录。留空时默认是 `dev/deploy/` 的上两级。
- `GIT_BRANCH`：部署分支。GitHub 常见默认分支是 `main`，如果仓库仍使用 `master`，这里要同步改成 `master`。
- `GIT_REMOTE_URL`：私有仓库需要服务器已有 SSH key，或在部署环境中配置带 token 的 HTTPS remote。不要提交真实 token。
- `WEBHOOK_SECRET`：GitHub Webhook Secret，必须和 GitHub 页面填写的一致。
- `WEBHOOK_BRANCH`：Webhook payload 的分支过滤。留空时使用 `GIT_BRANCH`。
- `CLOUDFLARE_ENABLED`：当前默认关闭，设为 `0` 时不会请求 Cloudflare API。
- 后台 `项目仓库地址` 会用于 Webhook 部署时设置 Git remote，也会用于 `deploy:build`。
- 后台 `核心仓库地址` 会用于 `update:core`。

## 2. 触发方式

推荐使用 PHP 入口方式：Git 平台请求 PHP 文件，PHP 校验密钥后执行 `webhook.sh deploy`。

Nginx + PHP-FPM 示例：

```nginx
location = /deploy {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME /www/wwwroot/weline/dev/deploy/webhook.php;
    fastcgi_param DEPLOY_CONFIG_FILE /www/wwwroot/weline/dev/deploy/.config;
    fastcgi_pass 127.0.0.1:9000;
}
```

健康检查：

```bash
curl -s 'https://example.com/deploy?health=1'
```

也可以使用常驻监听方式：`webhook.sh listen` 默认只监听 `127.0.0.1:9097`，再用 Nginx/Caddy 反代到公网 HTTPS。

## 3. GitHub 页面配置

进入 GitHub 仓库：

1. 打开 `Settings`。
2. 进入 `Webhooks`。
3. 点击 `Add webhook`。
4. `Payload URL` 填写公网地址，例如 `https://example.com/deploy`。
5. `Content type` 选择 `application/json`。
6. `Secret` 填写后台 `Webhook 密钥` 或 `.config` 中的 `WEBHOOK_SECRET`。
7. `Which events would you like to trigger this webhook?` 选择 `Just the push event`。
8. 勾选 `Active`。
9. 保存。

脚本会校验 GitHub 的 `X-Hub-Signature-256`，签名算法为 `HMAC-SHA256`，密钥就是 `WEBHOOK_SECRET`。

## 4. 请求验证

不要在有未提交代码的开发工作区里直接测试真实部署请求。需要验证时，请在专用部署环境使用 Bearer Token 验证入口和分支过滤：

```bash
curl -s -X POST 'https://example.com/deploy' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer 请替换为强随机密钥' \
  --data '{"ref":"refs/heads/main"}'
```

也可以模拟 GitHub 签名：

```bash
body='{"ref":"refs/heads/main"}'
secret='请替换为强随机密钥'
sig='sha256='$(printf '%s' "$body" | openssl dgst -sha256 -hmac "$secret" -hex | awk '{print $2}')

curl -s -X POST 'https://example.com/deploy' \
  -H 'Content-Type: application/json' \
  -H "X-Hub-Signature-256: $sig" \
  --data "$body"
```

成功时返回 `ok: true`。如果 `ref` 与 `WEBHOOK_BRANCH` 不一致，会返回 `skipped: true` 并不会部署。

## 5. 常见问题

- `403 invalid webhook token`：GitHub Secret 与 `WEBHOOK_SECRET` 不一致，或反向代理没有传递 `X-Hub-Signature-256`。
- `branch mismatch`：GitHub 推送分支与 `WEBHOOK_BRANCH` 不一致。注意 GitHub payload 通常是 `refs/heads/main`。
- `Repository not found` 或 `Authentication failed`：服务器上的 Git remote 没有权限拉取私有仓库。
- `Tracked files have local changes`：服务器部署目录存在本地改动。先人工确认并清理，不要随意开启 `DEPLOY_FORCE_RESET=1`。
- `Cloudflare: disabled`：这是当前默认行为，不是错误。

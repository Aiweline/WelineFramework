# Gitee Webhook 配置指南

本文说明如何把 Gitee Webhook 接到 `Weline_Deploy` 部署入口，让服务器收到 push 事件后更新当前 Git 代码。

后台配置页说明见 `app/code/Weline/Deploy/doc/backend-config.md`。

## 1. 服务器配置

可以在后台 `系统管理 > 系统维护 > 部署配置` 中配置项目仓库、核心仓库和 Webhook 信息。后台配置保存后优先于 `dev/deploy/.config`。

如果后台暂时不可用，在服务器项目目录中编辑 `dev/deploy/.config`：

```bash
DEPLOY_ROOT='/www/wwwroot/weline'
GIT_REMOTE='origin'
GIT_BRANCH='master'
GIT_UPDATE_MODE='reset'
DEPLOY_FORCE_RESET='0'
DEPLOY_SWITCH_BRANCH='0'

WEBHOOK_HOST='127.0.0.1'
WEBHOOK_PORT='9097'
WEBHOOK_PATH='/deploy'
WEBHOOK_SECRET='请替换为强随机密钥'
WEBHOOK_BRANCH='master'

CLOUDFLARE_ENABLED='0'
```

关键配置：

- `DEPLOY_ROOT`：服务器上的项目根目录。留空时默认是 `dev/deploy/` 的上两级。
- `GIT_BRANCH`：部署分支。建议显式配置，避免误部署当前 checkout 的其他分支。
- `DEPLOY_FORCE_RESET`：默认 `0`，检测到本地已跟踪文件改动会停止部署。专用部署目录才考虑设为 `1`。
- `WEBHOOK_SECRET`：Gitee Webhook 密码，必须和 Gitee 页面填写的一致。
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

## 3. Gitee 页面配置

进入 Gitee 仓库：

1. 打开 `管理`。
2. 进入 `WebHooks`。
3. 点击 `添加 WebHook`。
4. URL 填写公网地址，例如 `https://example.com/deploy`。
5. 密码/Token 填写后台 `Webhook 密钥` 或 `.config` 中的 `WEBHOOK_SECRET`。
6. 触发事件选择 `Push`。
7. 数据格式选择 `JSON`。
8. 保存并启用。

### Tag 发布（可选）

如果需要 tag 推送也触发部署：

1. 在后台 `部署配置` 的「触发模式」区域，将「部署触发方式」选为「仅 Tag Push」或「分支 + Tag 都生效」。
2. 可选填写「Tag 前缀过滤」（如 `v`），仅匹配此前缀的 tag 才会触发部署。
3. Gitee Webhook 页面：「触发事件」勾选「Tag Push」。
4. Tag 发布时，`deploy_version` 等于 tag 名（如 `v2.4.1`），而非 commit SHA。

脚本兼容两种 Gitee 鉴权形式：

- `X-Gitee-Token` 直接等于 `WEBHOOK_SECRET`。
- Gitee 发送 `X-Gitee-Timestamp` 时，`X-Gitee-Token` 为 HMAC 签名。

## 4. 请求验证

不要在有未提交代码的开发工作区里直接测试真实部署请求。需要验证时，请在专用部署环境使用：

```bash
curl -s -X POST 'https://example.com/deploy' \
  -H 'Content-Type: application/json' \
  -H 'X-Gitee-Token: 请替换为强随机密钥' \
  --data '{"ref":"refs/heads/master"}'
```

成功时返回 `ok: true`。如果 `ref` 与 `WEBHOOK_BRANCH` 不一致，会返回 `skipped: true` 并不会部署。

## 5. 常见问题

- `403 invalid webhook token`：Gitee 的密码/Token 与 `WEBHOOK_SECRET` 不一致，或反向代理没有传递请求头。
- `branch mismatch` / `trigger_mode_tag_only` / `trigger_mode_branch_only`：触发模式或分支不匹配。检查后台「部署触发方式」和「分支过滤」配置。
- `Tracked files have local changes`：服务器部署目录存在本地改动。先人工确认并清理，不要随意开启 `DEPLOY_FORCE_RESET=1`。
- `Cloudflare: disabled`：这是当前默认行为，不是错误。

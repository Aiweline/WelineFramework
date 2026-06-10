# GitHub Webhook 配置指南

将 GitHub 仓库 Webhook 接到 `Weline_Deploy` 统一入口 `https://<域名>/deploy`。

访问密码说明见 [`webhook-secret.md`](webhook-secret.md)；后台字段与 Nginx 见 [`backend-config.md`](backend-config.md)。

## 1. 配置访问密码

在服务器项目目录执行：

```bash
php bin/w deploy:webhook:setup --base-url=https://你的域名
```

将命令输出的 **Webhook 访问密钥** 记下；该值已写入后台「部署配置 > Webhook 密钥」（`webhook_secret`）。

刷新密码（轮换后须同步更新 GitHub Secret）：

```bash
php bin/w deploy:webhook:setup --force -y --url=https://你的域名/deploy
```

## 2. GitHub 页面配置

1. 仓库 → `Settings` → `Webhooks` → `Add webhook`
2. 填写：

| 字段 | 值 |
|------|-----|
| Payload URL | `https://你的域名/deploy` |
| Content type | `application/json` |
| Secret | 与后台「Webhook 密钥」**完全相同** |
| Events | `Just the push event` |
| Active | 勾选 |

3. 保存

### Tag 发布（可选）

1. 后台「部署触发方式」选「仅 Tag Push」或「分支 + Tag 都生效」
2. GitHub 默认 push 事件已包含 tag push
3. Tag 发布时 `deploy_version` 为 tag 名（如 `v2.4.1`）

GitHub 使用 `X-Hub-Signature-256`（HMAC-SHA256），密钥为 `webhook_secret`。

## 3. 验证

```bash
curl -s 'https://你的域名/deploy?health=1'

curl -s -X POST 'https://你的域名/deploy' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer 你的webhook_secret' \
  --data '{"ref":"refs/heads/main"}'
```

仅在专用部署环境测试。成功返回 `{"ok":true}`。

模拟 GitHub 签名：

```bash
body='{"ref":"refs/heads/main"}'
secret='你的webhook_secret'
sig='sha256='$(printf '%s' "$body" | openssl dgst -sha256 -hmac "$secret" -hex | awk '{print $2}')

curl -s -X POST 'https://你的域名/deploy' \
  -H 'Content-Type: application/json' \
  -H "X-Hub-Signature-256: $sig" \
  --data "$body"
```

## 4. 常见问题

- `403 invalid webhook token`：GitHub Secret 与 `webhook_secret` 不一致
- `branch mismatch`：注意 payload 为 `refs/heads/main` 等，与后台分支过滤一致
- `Authentication failed`：服务器 Git remote 无拉取私有仓库权限

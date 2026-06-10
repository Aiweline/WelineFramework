# Gitee Webhook 配置指南

将 Gitee 仓库 Webhook 接到 `Weline_Deploy` 统一入口 `https://<域名>/deploy`。

访问密码说明见 [`webhook-secret.md`](webhook-secret.md)；后台字段与 Nginx 见 [`backend-config.md`](backend-config.md)。

## 1. 配置访问密码

在服务器项目目录执行：

```bash
php bin/w deploy:webhook:setup --base-url=https://你的域名
```

将命令输出的 **Webhook 访问密钥** 记下；该值已写入后台「部署配置 > Webhook 密钥」（`webhook_secret`）。

刷新密码（轮换后须同步更新 Gitee 页面密码）：

```bash
php bin/w deploy:webhook:setup --force -y --url=https://你的域名/deploy
```

## 2. Gitee 页面配置

1. 打开仓库 → `管理` → `WebHooks` → `添加 WebHook`
2. 填写：

| 字段 | 值 |
|------|-----|
| URL | `https://你的域名/deploy` |
| 密码 / Token | 与后台「Webhook 密钥」**完全相同** |
| 触发事件 | `Push`（若需 Tag 发布，另勾选 `Tag Push` 并配置后台触发模式） |
| 数据格式 | JSON |

3. 保存并启用

### Tag 发布（可选）

1. 后台「部署触发方式」选「仅 Tag Push」或「分支 + Tag 都生效」
2. 可选「Tag 前缀过滤」（如 `v`）
3. Gitee 触发事件勾选「Tag Push」
4. Tag 发布时 `deploy_version` 为 tag 名（如 `v2.4.1`）

Gitee 鉴权兼容：`X-Gitee-Token` 明文等于 `webhook_secret`，或带 `X-Gitee-Timestamp` 的 HMAC 签名。

## 3. 验证

```bash
curl -s 'https://你的域名/deploy?health=1'

curl -s -X POST 'https://你的域名/deploy' \
  -H 'Content-Type: application/json' \
  -H 'X-Gitee-Token: 你的webhook_secret' \
  --data '{"ref":"refs/heads/master"}'
```

仅在专用部署环境测试。成功返回 `{"ok":true}`；`403` 表示密码与 `webhook_secret` 不一致。

## 4. 常见问题

- `403 invalid webhook token`：Gitee 密码与后台 `webhook_secret` 不一致
- `branch mismatch` / `trigger_mode_*`：检查后台触发模式与分支过滤
- `Tracked files have local changes`：部署目录有本地改动，先清理再部署

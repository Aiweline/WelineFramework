# Webhook 访问密码（webhook_secret）

Git 平台向站点 **随机 Webhook 公网路径**（`~wh~` 前缀，由 `deploy:webhook:setup` 生成）发起 `POST` 时，必须携带与框架一致的**访问密码**。配置项在后台称为「Webhook 密钥」，代码字段名为 `webhook_secret`。

**同一套密码用于所有 Git 平台**（Gitee、GitHub、自建等），与厂商无关；服务端只校验「请求中的密码是否与 `webhook_secret` 一致」。

## 配置方式

### 方式一：命令行（推荐）

首次生成并写入后台：

```bash
php bin/w command:upgrade -m Weline_Deploy

php bin/w deploy:webhook:setup --base-url=https://你的域名
# 或指定完整 URL
php bin/w deploy:webhook:setup --url=https://你的域名/deploy
```

命令会：

1. 生成强随机公网路径（`~wh~` + 32 位十六进制，若尚无有效路径）或沿用已有路径
2. 生成强随机密钥（若后台尚无密钥）或沿用已有密钥
3. 写入后台部署配置，并输出完整 Webhook URL、版本探测 URL、curl 与 Git 填表指引

**仅轮换公网路径**（不更换密钥）：

```bash
php bin/w deploy:webhook:setup --rotate-path -y --base-url=https://你的域名
```

**刷新（轮换）访问密码**——生成新密钥并覆盖后台，随后必须同步更新 Git 平台 Webhook 中的 Secret/密码字段：

```bash
php bin/w deploy:webhook:setup --force -y --url=https://你的域名/deploy
```

仅查看当前密钥与 curl 示例、不覆盖后台：

```bash
php bin/w deploy:webhook:setup --url=https://你的域名/deploy
# 已有密钥时不会重新生成；加 --no-save 可只打印、不写库
```

可选：同时生成部署用 SSH 密钥对（`var/deploy/ssh/`，不入 Git）：

```bash
php bin/w deploy:webhook:setup --base-url=https://你的域名 --ssh-key
```

### 方式二：后台手工填写

1. 进入 `系统管理 > 系统维护 > 部署配置`
2. 在「Webhook 密钥」输入强随机字符串（建议 32 位以上十六进制）
3. 保存后，将**完全相同**的值填入 Git 平台 Webhook 的 Secret / 密码 / Token 字段

留空保存表示**不修改**已有后台密钥。

### 方式三：服务器文件（仅后台不可用时）

在 `dev/deploy/.config` 中设置：

```bash
WEBHOOK_SECRET='与 Git 平台一致的强随机密钥'
```

优先级低于后台「部署配置」。

## Git 平台如何填写

| 平台 | URL | 密码字段 |
|------|-----|----------|
| Gitee | `https://域名/~wh~…`（setup 输出） | 密码 / Token → 填 `webhook_secret` |
| GitHub | 同上 | Secret → 填 `webhook_secret` |
| 其他 | 同上 | 按平台 Secret 字段填写相同值 |

路径由 `deploy:webhook:setup` 随机生成，**不要**使用 `/deploy` 等常见路径。框架通过 `Controller/Router.php`（`~wh~` 特征前缀）快速匹配后转发到内部 `deploy/webhook/deploy`。

版本探测：`https://域名/~wh~…/version`（可配合「发布探测 Token」）。

## 服务端如何校验

以下任一方式携带的密码与 `webhook_secret` 一致即通过：

| 方式 | 示例 |
|------|------|
| Bearer（推荐） | `Authorization: Bearer <webhook_secret>` |
| Gitee 明文 Token | `X-Gitee-Token: <webhook_secret>` |
| URL 参数 | `POST /deploy?token=<webhook_secret>` |
| Gitee HMAC | 配置了 Gitee 密码时，平台可发 HMAC 签名 |
| GitHub HMAC | `X-Hub-Signature-256`，密钥为 `webhook_secret` |

`GET /deploy?health=1` 健康检查**不需要**密码。

## 验证

在**专用部署环境**执行（勿在含未提交代码的开发机触发真实部署）：

```bash
# 健康检查
curl -s 'https://你的域名/deploy?health=1'

# 模拟推送（将 YOUR_SECRET 换为 webhook_secret）
curl -s -X POST 'https://你的域名/deploy' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer YOUR_SECRET' \
  --data '{"ref":"refs/heads/master"}'
```

返回 `{"ok":true}` 表示鉴权通过且已触发部署流程；`403 invalid webhook token` 表示密码不一致。

## 常见问题

- **403 invalid webhook token**：Git 平台 Secret 与后台 `webhook_secret` 不一致；轮换密钥后未同步平台。
- **WEBHOOK_SECRET is empty**：未执行 `deploy:webhook:setup` 且后台未填写密钥。
- **轮换后旧 Webhook 失效**：属预期行为；用 `--force` 后必须在所有 Git 平台更新 Secret。

## 相关文档

- [backend-config.md](backend-config.md) — 后台部署配置与 Nginx
- [gitee-webhook.md](gitee-webhook.md) — Gitee 填表步骤
- [github-webhook.md](github-webhook.md) — GitHub 填表步骤

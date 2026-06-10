# 后台部署配置指南

本文说明 `Weline_Deploy` 后台配置页的使用方式。后台配置保存后，会优先于 `dev/deploy/.config`、`.env` 和 `app/etc/env.php` 中的部署相关配置。

## 1. 后台入口

菜单位置：

```text
系统管理 > 系统维护 > 部署配置
```

对应后台路由：

```text
deploy/backend/config
```

首次上线该菜单和控制器后，需要在安全环境执行：

```bash
php bin/w setup:upgrade --route
```

不要在有未提交代码的开发工作区里触发部署或 Webhook 测试。

## 2. 配置优先级

部署配置按以下优先级读取：

1. 后台 `系统管理 > 系统维护 > 部署配置`
2. `dev/deploy/.config`
3. 项目根目录 `.env`
4. `app/etc/env.php`
5. 代码默认值

后台字段留空时，不会覆盖下层配置；密钥类字段留空保存时表示保留已有后台密钥。

## 3. 项目仓库

项目仓库用于 Webhook 部署和 `php bin/w deploy:build`。

建议填写：

- `项目仓库地址`：项目代码仓库，例如 `https://gitee.com/org/project.git`
- `项目分支`：生产部署分支，例如 `master` 或 `main`
- `Git Remote 名称`：通常是 `origin`
- `项目仓库用户名`：HTTPS token 方式可填 `git`、`oauth2` 或平台用户名
- `项目仓库 Token`：私有仓库拉取 token，留空保存时不修改已有后台 token

安全建议：

- 优先使用服务器 SSH key 或最小权限 token。
- 不要把 token 写进可提交的 `.config`。
- 生产部署目录应保持干净，不应有手工修改的已跟踪文件。

## 4. 核心仓库

核心仓库用于 `php bin/w update:core`。

建议填写：

- `核心仓库地址`：框架核心仓库，例如 `https://gitee.com/aiweline/WelineFramework.git`
- `核心默认分支`：例如 `master`
- `核心仓库用户名`：私有核心仓库需要时填写
- `核心仓库 Token`：私有核心仓库 token，留空保存时不修改已有后台 token

后台核心仓库配置会覆盖 `app/etc/env.php` 的 `core_update` 和 `.env` 的 `CORE_UPDATE_*`。

核心更新只维护框架核心目录。以下项目级模块目录不会被 `php bin/w update:core` 拷贝到目标项目，目标项目如需使用应自行维护：

- `app/code/Aiweline`
- `app/code/WeShop`

## 5. Webhook

Webhook 由 **Weline_Deploy 模块统一入口**处理：Git 平台 `POST` 到站点 `/deploy`（或后台配置的 `请求路径`），框架校验访问密码后执行 `dev/deploy/webhook.sh deploy`。

```text
Git 平台 Webhook → Nginx（HTTPS）→ WLS → deploy/webhook/deploy → webhook.sh deploy
```

### 5.1 Webhook 访问密码（webhook_secret）

访问密码是框架与 Git 平台共用的**唯一密钥**，后台字段名为「Webhook 密钥」（`webhook_secret`）。Gitee 的「密码」、GitHub 的「Secret」等须填写**完全相同**的值。

**推荐：用命令生成或刷新**

```bash
# 首次：生成密钥并写入后台，输出 curl 与填表指引
php bin/w deploy:webhook:setup --base-url=https://你的域名

# 刷新（轮换）访问密码：生成新密钥并覆盖后台，须同步更新 Git 平台
php bin/w deploy:webhook:setup --force -y --url=https://你的域名/deploy

# 仅查看当前密钥与 curl 示例（不覆盖已有密钥）
php bin/w deploy:webhook:setup --url=https://你的域名/deploy
```

也可在下方「Webhook 密钥」手工填写；留空保存表示不修改已有密钥。详细说明见 [`doc/webhook-secret.md`](webhook-secret.md)。

后台建议同时确认：

- `请求路径`：默认 `/deploy`
- `Bash 命令`：默认 `bash`，服务器路径特殊时可填 `/bin/bash`

### 5.2 服务器 Nginx 配置

将公网 HTTPS 反代到 WLS 监听地址（端口以实际 `server:start` / 实例配置为准）：

```nginx
location / {
    proxy_pass http://127.0.0.1:9501;
    proxy_http_version 1.1;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
}
```

`/deploy` 与站点其他路径走同一条 WLS 反代即可，无需单独 fastcgi 或额外监听端口。

健康检查：

```bash
curl -s 'https://你的域名/deploy?health=1'
# 返回 {"ok":true} 表示入口可达
```

### 5.3 Gitee 配置步骤

1. 打开 Gitee 仓库页面，点击顶部「管理」。
2. 左侧菜单找到「WebHooks」，点击「添加 WebHook」。
3. 填写以下信息：
   - **URL**：`https://你的域名/deploy`
   - **密码/Token**：填写后台「Webhook 密钥」中保存的值
   - **触发事件**：勾选「Push」
   - **数据格式**：选择「JSON」
4. 点击「保存并启用」。

验证（在专用部署环境执行）：

```bash
curl -s -X POST 'https://你的域名/deploy' \
  -H 'Content-Type: application/json' \
  -H 'X-Gitee-Token: 你的密钥' \
  --data '{"ref":"refs/heads/master"}'
```

返回 `{"ok":true}` 表示部署触发成功。

Gitee 兼容两种鉴权形式：`X-Gitee-Token` 直接等于密钥，或 `X-Gitee-Timestamp` + `X-Gitee-Token` HMAC 签名。

### 5.4 GitHub 配置步骤

1. 打开 GitHub 仓库页面，点击「Settings」。
2. 左侧菜单找到「Webhooks」，点击「Add webhook」。
3. 填写以下信息：
   - **Payload URL**：`https://你的域名/deploy`
   - **Content type**：选择 `application/json`
   - **Secret**：填写后台「Webhook 密钥」中保存的值
   - **Events**：选择「Just the push event」
4. 勾选「Active」，点击「Add webhook」。

验证（在专用部署环境执行）：

```bash
curl -s -X POST 'https://你的域名/deploy' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer 你的密钥' \
  --data '{"ref":"refs/heads/main"}'
```

GitHub 校验签名算法为 `HMAC-SHA256`（`X-Hub-Signature-256` 头），密钥就是后台填写的「Webhook 密钥」。

### 5.5 其他平台（通用）

本系统兼容标准 Webhook 协议，支持以下鉴权方式：

| 方式 | 说明 |
|------|------|
| `Authorization: Bearer <密钥>` | 通用 HTTP Bearer Token |
| `?token=<密钥>` | URL 参数传递 |
| `X-Gitee-Token` | Gitee 专用（自动识别） |
| `X-Hub-Signature-256` | GitHub 专用（HMAC-SHA256） |

**Payload 要求**：请求体为 JSON 格式，必须包含 `ref` 字段：

```json
{"ref": "refs/heads/main"}
```

`ref` 值格式：

- 分支推送：`refs/heads/<分支名>`（如 `refs/heads/main`）
- Tag 推送：`refs/tags/<tag名>`（如 `refs/tags/v1.0.0`）

**手动测试**：

```bash
curl -s -X POST 'https://你的域名/deploy' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer 你的密钥' \
  --data '{"ref":"refs/heads/main"}'
```

返回 `{"ok":true}` 表示部署触发成功；`{"skipped":true,"reason":"branch_mismatch"}` 表示分支不匹配，不会部署。

## 6. 部署行为

关键选项：

- `Git 更新模式`
  - `reset`：fetch 后 reset 到目标分支，部署结果更确定。
  - `pull_ff_only`：只允许快进，遇到分叉失败。
- `允许覆盖本地改动`
  - 默认关闭。开启会允许 `reset --hard` 覆盖服务器本地已跟踪改动。
- `允许切换分支`
  - 默认关闭。当前分支和目标分支不一致时，开启后才允许切换。
- `更新 Submodule`
  - 开启后部署会执行 submodule 更新。
- `运行 Composer`
  - 开启后部署会执行 `Composer 命令`。
- `部署后命令`
  - 可写 `php bin/w setup:upgrade --route && php bin/w server:reload -r` 这类后置动作。

## 7. 触发模式

触发模式决定 Webhook 何时触发部署。在后台配置页的「触发模式」区域，通过「部署触发方式」下拉框选择：

| 模式 | 含义 | 版本来源 |
|------|------|----------|
| **仅分支 Push** | 只有分支推送触发部署，tag 推送被忽略 | commit 短 SHA（如 `a3f5c2d`） |
| **仅 Tag Push** | 只有 tag 推送触发部署，分支推送被忽略 | tag 名（如 `v2.4.1`） |
| **分支 + Tag 都生效** | 两者都触发（默认） | 分支 = SHA，tag = tag 名 |

### 过滤规则

- `分支过滤`（原 Webhook 分支）：留空匹配所有分支；填写后仅该分支的 push 触发部署。
- `Tag 前缀过滤`：留空匹配所有 tag；填写（如 `v`）后仅匹配此前缀的 tag 才触发部署。

### 向后兼容

旧配置 `webhook_allow_tag_deploy`（0/1 开关）仍然生效：值为 `1` 时等价于「分支 + Tag 都生效」，值为 `0` 时等价于「仅分支 Push」。新配置 `deploy_trigger_mode` 优先。

### Git 平台配置提示

- **Gitee**：「触发模式」选「仅 Tag Push」时，Webhook 触发事件需额外勾选「Tag Push」；选「仅分支 Push」或「都生效」时勾选「Push」即可。
- **GitHub**：默认「Just the push event」已包含分支和 tag push，无需额外配置。

### 测试

```bash
# 测试分支触发
curl -s -X POST 'https://你的域名/deploy' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer 你的密钥' \
  --data '{"ref":"refs/heads/main"}'

# 测试 Tag 触发
curl -s -X POST 'https://你的域名/deploy' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer 你的密钥' \
  --data '{"ref":"refs/tags/v1.0.0"}'
```

返回 `{"ok":true}` 表示部署触发成功；`{"skipped":true,"reason":"trigger_mode_tag_only"}` 表示当前触发模式不匹配，不会部署。

### 发布探测 Token

可选。填写后，访问 `GET /deploy/version?token=xxx` 可查看详细版本信息（含 commit、分支、Worker ID）。无 token 时，`GET /deploy/version` 仅返回最小信息（版本号、发布 ID、ref 类型）。

发布历史页面：`系统管理 > 系统维护 > 发布历史`，可查看每次发布的版本、状态、耗时。

## 8. Cloudflare

Cloudflare 默认关闭。

只有同时满足以下条件才会清理缓存：

- `启用缓存清理` 已打开
- `Cloudflare Zone ID` 已填写
- `Cloudflare API Token` 已填写

Token 至少需要对应 Zone 的 Cache Purge 权限。

## 9. 发布命令参考

| 命令 | 说明 |
|------|------|
| `php bin/w deploy:build` | 仅 Git 拉取（轻量，不含版本戳） |
| `php bin/w deploy:release` | 完整发布：Git + 后置命令 + 版本戳 + reload |
| `php bin/w deploy:release -r refs/tags/v1.0.0` | Tag 发布 |
| `php bin/w deploy:release:status` | 查看当前部署版本 |
| `php bin/w deploy:release:wait --expect=v1.0.0` | CI 门禁：等待版本生效 |

## 10. 关联指南

- `app/code/Weline/Deploy/doc/webhook-secret.md`：访问密码配置与轮换命令
- `app/code/Weline/Deploy/doc/gitee-webhook.md`：Gitee Webhook 填表步骤
- `app/code/Weline/Deploy/doc/github-webhook.md`：GitHub Webhook 填表步骤
- `dev/deploy/.config.exsample`：服务器文件配置示例（后台不可用时）

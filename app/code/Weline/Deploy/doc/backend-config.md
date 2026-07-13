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

### 2.1 WLS Panel 项目发布预检

WLS Panel 的 Deploy 插件页会按当前项目上下文加载项目 Profile，并展示「发布预检」。
点击「执行预检」只会重新计算并展示这些检查结果，不会进入真实发布。

预检只做静态检查，不执行真实发布：

- Profile 来源：独立 Profile 已启用、已保存未启用，或继承全局配置。
- 仓库配置：项目仓库地址必须是 `http(s)`、`ssh://` 或 `git@host:path.git` 形式。
- 部署目录：检查是否填写以及是否包含不适合发布配置的控制字符。
- 触发模式：默认建议仅 Tag Push；分支发布会以需要确认状态提示。
- Webhook 入口：检查随机 `~wh~` 路径和 `webhook_secret` 是否已配置。
- 命令白名单：Composer 与部署后命令必须通过 WLS 发布命令白名单。

预检不会执行 Git、创建目录、写入版本戳、运行后置命令或重载 WLS。手动发布、Webhook 回放和回滚动作都会在服务端重新加载当前项目 Profile，并复用同一套检查结果作为操作前置条件。

回滚策略：项目 Profile 的 `rollback_ref` 会在保存前通过 WLS Deploy 命令策略归一化。
允许值包括 tag-like ref、`refs/tags/name`、`refs/heads/name` 或 7-40 位 commit SHA；
会拒绝 shell 控制字符、含糊的 `..` / `//` / `@{`、短横线开头 ref，以及点号或斜杠结尾 ref。
WLS Deploy 预检会显示独立的 rollback policy 卡片。面板里的 `Run Rollback` 是真实 Git 回滚动作：
它只读取已保存的项目 Profile `rollback_ref`，要求 POST、`confirm_rollback=1`、已启用 Profile、非 `danger` 预检和非空安全 ref，
然后由 `DeployOrchestratorService::rollback()` 在选中项目的有效 `deploy_root` 中执行回滚、写入 `var/deploy/current.json`，并记录项目级发布历史。

### 2.2 WLS Panel Webhook 回放预检

WLS Panel 的 Deploy 插件页还提供「Webhook 回放预检」。它用于在面板里输入 Git 平台 payload 的 `ref` 值，并复用当前项目的有效 Profile / 全局配置判断是否会触发发布。

示例：

```text
refs/tags/v1.0.0
refs/heads/main
```

回放只调用 `DeployWebhookRefResolver::resolve()`，展示：

- `ready`：当前触发模式、Tag 前缀和分支过滤均命中；真实 Webhook 会进入发布流程。
- `skipped`：当前策略会跳过该事件，例如默认 tag-only 模式下的分支 push 返回 `trigger_mode_tag_only`。

回放预检不会调用 `DeployWebhookReleaseService::releaseFromWebhook()`，也不会调用 `DeployOrchestratorService::release()`，因此不会执行 Git、写文件、运行命令、写版本戳或重载 WLS。

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

推荐直接指定目标分支，例如 `php bin/w core:update master`（仍兼容
`php bin/w update:core -b master`）。命令在下载和复制任何文件之前检查当前
项目 Git 工作区；已跟踪、未跟踪或子模块存在未提交变更时默认拒绝更新。
只有明确接受覆盖风险时才使用 `-f/--force` 绕过该保护。命令不会自动清理、
恢复或提交本地变更。

建议填写：

- `核心仓库地址`：框架核心仓库，例如 `https://gitee.com/aiweline/WelineFramework.git`
- `核心默认分支`：例如 `master`
- `核心仓库用户名`：私有核心仓库需要时填写
- `核心仓库 Token`：私有核心仓库 token，留空保存时不修改已有后台 token

后台核心仓库配置会覆盖 `app/etc/env.php` 的 `core_update` 和 `.env` 的 `CORE_UPDATE_*`。

核心更新会增量同步以下根目录（Git 有变更的文件直接覆盖；其余文件按大小/hash 与源不一致则覆盖；`vendor` 不更新）：

- `app`
- `bin`
- `pub`
- `setup`
- `dev`

以下项目级模块目录不会被 `php bin/w update:core` 拷贝到目标项目，目标项目如需使用应自行维护：

- `app/code/Aiweline`
- `app/code/WeShop`

以下文件若已存在于目标项目则不会被覆盖：

- `app/etc/env.php`
- 项目根目录 `.env`
- `dev/deploy/.config`

## 5. Webhook

Webhook 由 **Weline_Deploy 模块统一入口**处理：Git 平台 `POST` 到 `deploy:webhook:setup` 输出的随机 `~wh~` 公网路径，框架校验访问密码后进入 `DeployOrchestratorService::release()` 完整发布编排。

```text
Git 平台 Webhook → Nginx（HTTPS）→ WLS → ~wh~ 随机路径 → deploy/webhook/deploy → DeployOrchestratorService::release()
```

### 5.1 Webhook 访问密码（webhook_secret）

访问密码是框架与 Git 平台共用的**唯一密钥**，后台字段名为「Webhook 密钥」（`webhook_secret`）。Gitee 的「密码」、GitHub 的「Secret」等须填写**完全相同**的值。

**推荐：用命令生成或刷新**

```bash
# 首次：生成密钥并写入后台，输出 curl 与填表指引
php bin/w deploy:webhook:setup --base-url=https://你的域名

# 刷新（轮换）访问密码：生成新密钥并覆盖后台，须同步更新 Git 平台
php bin/w deploy:webhook:setup --force -y --base-url=https://你的域名

# 仅查看当前密钥与 curl 示例（不覆盖已有密钥）
php bin/w deploy:webhook:setup --base-url=https://你的域名
```

也可在下方「Webhook 密钥」手工填写；留空保存表示不修改已有密钥。详细说明见 [`doc/webhook-secret.md`](webhook-secret.md)。

后台建议同时确认：

- `请求路径`：由命令生成，默认形如 `~wh~...`，不要手写 `/deploy`
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

Webhook 随机路径与站点其他路径走同一条 WLS 反代即可，无需单独 fastcgi 或额外监听端口。

健康检查：

```bash
curl -s 'https://你的域名/~wh~...?health=1'
# 返回 {"ok":true} 表示入口可达
```

### 5.3 Gitee 配置步骤

1. 打开 Gitee 仓库页面，点击顶部「管理」。
2. 左侧菜单找到「WebHooks」，点击「添加 WebHook」。
3. 填写以下信息：
   - **URL**：命令输出的 `https://你的域名/~wh~...`
   - **密码/Token**：填写后台「Webhook 密钥」中保存的值
   - **触发事件**：勾选「Push」
   - **数据格式**：选择「JSON」
4. 点击「保存并启用」。

验证（在专用部署环境执行）：

```bash
curl -s -X POST 'https://你的域名/~wh~...' \
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
   - **Payload URL**：命令输出的 `https://你的域名/~wh~...`
   - **Content type**：选择 `application/json`
   - **Secret**：填写后台「Webhook 密钥」中保存的值
   - **Events**：选择「Just the push event」
4. 勾选「Active」，点击「Add webhook」。

验证（在专用部署环境执行）：

```bash
curl -s -X POST 'https://你的域名/~wh~...' \
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
curl -s -X POST 'https://你的域名/~wh~...' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer 你的密钥' \
  --data '{"ref":"refs/heads/main"}'
```

返回 `{"ok":true}` 表示部署触发成功；`{"skipped":true,"reason":"branch_mismatch"}` 表示分支不匹配，不会部署。

### 5.6 WLS Panel 项目级 Webhook 上下文

WLS Panel 管理多个子项目时，真实 webhook 可以带上项目上下文，让
`Weline_Deploy` 在发布前读取对应的项目 Profile：

```text
https://你的域名/~wh~随机路径?project_id=12&domain=shop.example.com&project_type=wls
```

也可以放在 JSON payload 顶层，或放在 `wls`、`wls_project`、`project`
对象内：

```json
{
  "ref": "refs/tags/v1.0.0",
  "wls_project": {
    "project_id": "12",
    "domain": "shop.example.com",
    "project_type": "wls"
  }
}
```

支持字段：

- `profile_key`
- `project_id`
- `domain`
- `project_type`

生效规则：

- 随机 `~wh~` 路径仍是全局入口路径。
- 如果上下文命中已启用的 `DeployProjectProfile`，并且该 Profile 配置了
  项目级 `webhook_secret`，Controller 会在验签前解析项目有效配置，并使用
  项目密钥验签；此时全局密钥不会通过该项目的 webhook 请求。
- 如果项目 Profile 未配置独立密钥，则回退到全局 `webhook_secret`。
- 如果上下文命中已启用的 `DeployProjectProfile`，发布还会使用该 Profile
  的仓库、分支、remote、触发模式、Tag/Branch 过滤、备份开关、Composer
  命令、部署后命令和 `deploy_root`。
- `deploy_root` 是真实执行目录。Git 更新、备份源目录、部署后命令、
  `var/deploy/current.json` 和 `server:reload` 都会在该目录下运行。
- 配置了 `deploy_root` 时必须是已存在的绝对路径；相对路径或不存在的目录会让发布失败，避免误跑到宿主 WLS 项目。
- 未传项目上下文或未命中启用 Profile 时，保持原来的全局 Deploy 配置行为。
- WLS Panel 插件商城的环境地址按发布环境区分：本地开发使用 App 商城项目
  `E:\WelineFramework\Framework-Official\App` 的
  `https://app.weline.test:9523`；部署后和生产探测使用
  `https://app.aiweline.com`。发布自动化和部署探测不要使用
  `www.weline.test:9518` 或 `www.aiweline.com` 作为应用商城地址。
- 发布成功写入 `var/deploy/current.json` 时会固化
  `deploy_mode_source`、`appstore_environment`、`appstore_platform_url` 和
  `appstore_platform_url_source`。只有 `app/etc/env.php` 显式配置
  `system.deploy=dev/local` 或根级 `deploy=dev/local` 时，才读取本地
  `WELINE_APPSTORE_PLATFORM_URL` 或 `appstore.platform_url`，且归一化后必须
  等于 `https://app.weline.test:9523`；非本地部署模式或未显式配置
  `deploy` 模式时固定写入 `https://app.aiweline.com`，供部署后测试自动
  选择线上应用商城。
- AppStore 客户端运行时也按同一规则读取：显式本地模式只接受锁定的
  `https://app.weline.test:9523`；否则当 `var/deploy/current.json` 标记
  `appstore_environment=production` 时，优先使用其中的
  `appstore_platform_url`。生产部署信息只有同时记录
  `appstore_platform_url=https://app.aiweline.com` 和
  `appstore_platform_url_source=production_default` 才会被运行时当作有效
  AppStore 根地址；否则运行时回退到锁定的线上商城根地址，部署验收门禁
  会拒绝该漂移，避免部署后残留的本地配置继续影响 WLS Panel 插件商城、
  授权和下载安装链路。

安全验证建议：

1. 先在 WLS Deploy 插件页保存并启用项目 Profile。
2. 用「Webhook 回放预检」确认 `refs/tags/...` 或 `refs/heads/...` 会返回预期的 `ready/skipped`。
3. 再用带项目上下文的 curl 请求真实 webhook。上线前优先使用仅会被策略跳过的 ref 验证上下文是否命中，再执行可发布 ref。
4. 发布后用同一项目上下文访问健康检查和版本探测：

```bash
curl -s 'https://你的域名/~wh~...?health=1&project_id=12'
curl -s 'https://你的域名/~wh~.../version?project_id=12'
```

带项目上下文时，健康检查和版本探测会读取命中的项目 Profile 的
`deploy_root/var/deploy/current.json`；未传上下文或未命中启用 Profile 时，
保持读取全局 Deploy 配置对应的运行时版本。

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
| **分支 + Tag 都生效** | 两者都触发（需明确选择） | 分支 = SHA，tag = tag 名 |

### 过滤规则

- `分支过滤`（原 Webhook 分支）：留空匹配所有分支；填写后仅该分支的 push 触发部署。
- `Tag 前缀过滤`：留空匹配所有 tag；填写（如 `v`）后仅匹配此前缀的 tag 才触发部署。

### 向后兼容

默认模式为「仅 Tag Push」。旧配置 `webhook_allow_tag_deploy`（0/1 开关）仍然生效：值为 `1` 时等价于「分支 + Tag 都生效」，值为 `0` 时等价于「仅分支 Push」。新配置 `deploy_trigger_mode` 优先。

### Git 平台配置提示

- **Gitee**：「触发模式」选「仅 Tag Push」时，Webhook 触发事件需额外勾选「Tag Push」；选「仅分支 Push」或「都生效」时勾选「Push」即可。
- **GitHub**：默认「Just the push event」已包含分支和 tag push，无需额外配置。

### 测试

上线前可以先在 WLS Panel Deploy 插件页使用「Webhook 回放预检」输入相同 `ref`，确认 Tag / 分支策略是否会返回 `ready` 或 `skipped`。该面板预检不会执行真实发布；下面的 `curl` 才会触发真实 Webhook 入口。

```bash
# 测试分支触发
curl -s -X POST 'https://你的域名/~wh~...' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer 你的密钥' \
  --data '{"ref":"refs/heads/main"}'

# 测试 Tag 触发
curl -s -X POST 'https://你的域名/~wh~...' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer 你的密钥' \
  --data '{"ref":"refs/tags/v1.0.0"}'
```

返回 `{"ok":true}` 表示部署触发成功；`{"skipped":true,"reason":"trigger_mode_tag_only"}` 表示当前触发模式不匹配，不会部署。

### 发布探测 Token

可选。填写后，访问随机 Webhook 路径后加 `/version?token=xxx` 可查看详细版本信息（含 commit、分支、Worker ID）。无 token 时，`GET <随机 Webhook 路径>/version` 仅返回最小信息（版本号、发布 ID、ref 类型）。

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

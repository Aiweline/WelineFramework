# Cloudflare OAuth Client 配置指南（Weline_Cdn）

本指南说明在 Cloudflare 控制台创建 **OAuth Client** 时，各下拉项应如何选择，以及与 Weline 后台配置的对应关系。

后台入口：系统配置 → 模块 `Weline_Cdn` → 模板「Cloudflare OAuth 应用」。

控制台入口：https://dash.cloudflare.com/?to=/:account/oauth-clients

官方文档：https://developers.cloudflare.com/fundamentals/oauth/create-an-oauth-client/

---

## Weline 推荐组合（直接照抄）

Weline CDN 一键授权是 **服务端机密客户端**：Client Secret 仅保存为服务器环境变量，用户令牌密封在 Cdn Account 的 secret_ref 中。授权码只交换一次，Access Token 到期后使用 Refresh Token 自动续期。

| Cloudflare 表单字段 | 应选 / 应填 | 说明 |
| --- | --- | --- |
| 客户端名称 | 任意，如 Weline | 仅展示名 |
| **响应类型** | **Code** | 下拉里有 Token / ID Token / Code：**只选 Code**；不要选 Token 或 ID Token |
| **授权类型** | **Authorization Code + Refresh Token** | Refresh Token 会让 CF **自动**追加协议 scope `offline_access`（勾选列表里**没有** offline_access 项） |
| **令牌身份验证方法** | **Client Secret Post** 或 **Client Secret Basic** | **不要选 None**（None 属于本字段，不是「响应类型」） |
| **重定向（回调）URL** | 账户页黄框整段（**无** `/CNY/` 货币段） | 必须与后台生成 URL 完全一致；可登记多条 |
| **Scopes（选择权限范围）** | 见下一节 | 默认硬性：`zone.read dns.write cache.purge cache-settings.write`（不含 offline_access） |

> 易混点：「不要选 Token / None」其实是**两个字段**——响应类型不要 Token/ID Token；令牌身份验证方法不要 None。

---

## 选择权限范围（Cloudflare 向导第 2 步）

控制台左侧「选择权限范围」对照（中文界面）。**清缓存与缓存规则是默认必选，不是可选项。**

| 控制台勾选 | 填进 OAuth Scopes 的 id | 用途 |
| --- | --- | --- |
| **Zone Read**（DNS 和区域） | `zone.read` | 列/读 Zone |
| **DNS Write**（DNS Edit） | `dns.write` | 企业邮箱一键写 MX/SPF 等 |
| **Cache Purge**（缓存清理） | `cache.purge` | CDN 清缓存 |
| **Cache Settings Write**（或 Cache Rules Edit） | `cache-settings.write` | 推送/管理缓存规则（`http_request_cache_settings`） |

> **不要**在权限勾选列表里找 `offline_access`——它不存在；勾选 **授权类型 → Refresh Token** 后由 Cloudflare **自动**追加协议 scope。

后台「OAuth Scopes」字段默认（原样）：

```text
zone.read dns.write cache.purge cache-settings.write
```

若控制台对缓存规则显示的 id 是 `cache-rules.write`，用它替换 `cache-settings.write`。代码硬校验见 `CloudflareOAuthService::REQUIRED_SCOPES`。

**不要勾**：开发人员平台、AI 和机器学习、应用安全性、Cloudflare One / Zero Trust、分析和日志。

下一步 **Choose optional scopes**：上面勾过的项全部保持 Required，不要改成可选。

平台管理员只配置一次：

1. WELINE_CLOUDFLARE_OAUTH_CLIENT_ID
2. WELINE_CLOUDFLARE_OAUTH_CLIENT_SECRET
3. 可选 WELINE_CLOUDFLARE_OAUTH_TOKEN_AUTH_METHOD（client_secret_post 或 client_secret_basic）
4. 可选 WELINE_CLOUDFLARE_OAUTH_SCOPES；**必须**包含 zone.read、dns.write、cache.purge、cache-settings.write（**不要**填 offline_access）

之后企业邮箱用户在域名面板点击“连接或重新授权 Cloudflare”即可，不再复制 API Token。Client Secret、Access Token、Refresh Token 禁止写入源码、模板、日志或沟通记录。

---

## 几种组合分别是什么

### 1. 机密客户端 / 服务端应用（Weline 使用）

- 响应类型：`Code`
- 授权类型：`Authorization Code`（+ 可选 `Refresh Token`）
- 令牌身份验证：`Client Secret Post` 或 `Client Secret Basic`

用户同意授权后，Cloudflare 把 `code` 回调到你们后台；后台再用 Client Secret 换 Access Token。Secret 只留在服务端。

Weline 实现按官方机密客户端建议，**始终附带 PKCE（S256）**：授权 URL 带 `code_challenge` / `code_challenge_method=S256`，换票时提交 `code_verifier`。即使控制台误选了 `None`，也有机会完成授权；推荐仍选 Client Secret Post/Basic。

删除或重新授权 OAuth 账户时，会尽最大努力调用 `https://dash.cloudflare.com/oauth2/revoke` 吊销旧 refresh/access token。

### 2. 公开客户端 / 纯浏览器或 CLI（Weline 不要用）

- 响应类型：`Code`
- 授权类型：`Authorization Code`
- 令牌身份验证：`None`（通常还要 PKCE）

没有 Client Secret，适合无法安全保存密钥的场景。本模块配置里有 Secret 字段，不走这条。

### 3. Implicit / Token 或 ID Token 响应（过时，禁止）

- 响应类型：`Token` 或 `ID Token`

Access Token / ID Token 直接出现在浏览器 URL，不安全，且与「后台用 code 换 token」流程不兼容。Weline 只认 **Code**。

### 4. 纯机器间 Client Credentials

用于服务对服务、无用户登录的场景，**不是**「用户点一键授权登录 CDN」的流程。

---

## 回调 URL 怎么写

**以 CDN → 账户页黄框整段为准**（代码已用 `withoutStorefrontLocalizationPrefix` **去掉**货币/语言段，避免切 CNY/USD 换出另一条 URI）。

示例形态（域名、端口、后台 key 换成你的；**不要**再手加 `/CNY/` / `zh_Hans_CN`）：

```text
https://p05113ef3.test.weline.com:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/cdn/backend/oauth/callback
```

注意：

- 必须含后台管理 key 段。
- 协议（`https` / `http`）、主机、端口、路径都必须与黄框逐字一致。
- Cloudflare 要求 Redirect URI 精确匹配；多一个斜杠、换端口、多写 `/CNY/` 都会失败。若 Client 里还留着旧的带 `/CNY/` 的条目，可保留作兼容，但**授权实际发出的是无货币段的那条**，必须也登记上。
- **Client ID ≠ Client Secret**。两栏填成同一串时，Cloudflare 常返回 `invalid_client`；其 `/oauth/error` 页还可能再显示 Cloudflare 自身的 404。
- **对照「客户端已创建」弹窗粘贴**。上面「您的客户端密钥」→ `Client Secret`（常以 **`cfoc_`** 开头）；下面「Your Client ID」→ `Client ID`（**32 位十六进制**）。**不要对调**。Account ID 也是 32 位 hex，请只从 OAuth 创建弹窗复制 Client ID，不要从账户概览 URL 抄。
- 把 `cfoc_…` 填进 Client ID、把 32 位 hex 填进 Secret，会触发授权失败，同样可能落到 `/oauth/error` 404。
- Secret 若创建后未保存，只能在 OAuth clients 里 **Rotate client secret** 再复制新 Secret。
- **Private Client**：默认私有，**只有创建该 Client 的 Cloudflare 账户成员**能完成同意授权。要用别的 Cloudflare 登录点授权，须把 Client 升为 Public（需域名验证）或把对方加成该账户成员。

---

## 与 API Token 的区别

| 方式 | 用途 | 配置位置 |
| --- | --- | --- |
| OAuth Client + 用户授权 | 用户登录 Cloudflare 并同意授权，系统换取 Access Token | 系统配置「Cloudflare OAuth」+ CDN 账户绑定流程 |
| API Token | 人工创建长期 Token，直接填入 CDN 账户 | CDN 账户表单；权限见 `Cloudflare-API-Token-Permissions.md` |

OAuth **不能**在无任何凭据时由外部系统自动在用户账号里创建 Client；创建 Client 仍需控制台或带 `OAuth Clients Write` 权限的 API Token。用户授权（consent）才是「一键」。

---

## 排错清单

1. 授权后跳错页 / 404：回调 URL 与后台真实路径不一致。
2. Cloudflare `/oauth/error` + `invalid_client`：Client ID 与 Secret 填成一样，或 Secret 错误。
3. Cloudflare `/oauth/error` + `invalid_request`：常见是回调 URL 未精确登记、认证方法选了 `None` 却未带 PKCE（Weline 现已默认带 S256）、或 Client 未勾选请求的 scopes。
4. 换 Token 失败、提示 client 认证错误：认证方法选了 `None`，或密钥填错/未保存。
5. 授权成功但 CDN 接口 403：Client 勾选的 scopes 不足，或与 `oauth_scopes` / `WELINE_CLOUDFLARE_OAUTH_SCOPES` 配置不一致。
6. 授权成功但无 Refresh Token：OAuth Client **授权类型未勾 Refresh Token**（不是缺 offline_access 勾选项——该项本来就不在列表里）。
7. 控制台 deep link 没进对的账户：先打开 https://dash.cloudflare.com/ 选账户，再进 OAuth clients。

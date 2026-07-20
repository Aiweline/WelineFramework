# Cloudflare OAuth Client 配置指南（Weline_Cdn）

本指南说明在 Cloudflare 控制台创建 **OAuth Client** 时，各下拉项应如何选择，以及与 Weline 后台配置的对应关系。

后台入口：系统配置 → 模块 `Weline_Cdn` → 模板「Cloudflare OAuth 应用」。

控制台入口：https://dash.cloudflare.com/?to=/:account/oauth-clients

官方文档：https://developers.cloudflare.com/fundamentals/oauth/create-an-oauth-client/

---

## Weline 推荐组合（直接照抄）

Weline CDN 一键授权是 **服务端机密客户端**：后台保存 Client Secret，用 Authorization Code 换 Access Token。

| Cloudflare 表单字段 | 应选 / 应填 | 说明 |
| --- | --- | --- |
| 客户端名称 | 任意，如 `Weline` | 仅展示名 |
| **响应类型** | **Code** | 禁止选 `Token` |
| **授权类型** | **Authorization Code** | 需要长期会话时可再加 **Refresh Token** |
| **令牌身份验证方法** | **Client Secret Post** 或 **Client Secret Basic** | 禁止选 `None` |
| **重定向（回调）URL** | `https://{站点域名}/{后台key}/cdn/backend/oauth/callback` | 必须与真实后台 URL 完全一致 |
| 客户端 URL | 可选，站点首页 | 公开客户端时可能必填 |

创建成功后：

1. 复制 **Client ID**、**Client Secret** 填回本站「Cloudflare OAuth」配置。
2. 勾选的 Scopes 与配置项 `cdn/cloudflare/oauth_scopes` 保持一致（默认建议 `account.read zone.read`，按 CDN 能力再补 Cache Purge / Cache Rules 等）。

---

## 几种组合分别是什么

### 1. 机密客户端 / 服务端应用（Weline 使用）

- 响应类型：`Code`
- 授权类型：`Authorization Code`（+ 可选 `Refresh Token`）
- 令牌身份验证：`Client Secret Post` 或 `Client Secret Basic`

用户同意授权后，Cloudflare 把 `code` 回调到你们后台；后台再用 Client Secret 换 Access Token。Secret 只留在服务端。

### 2. 公开客户端 / 纯浏览器或 CLI（Weline 不要用）

- 响应类型：`Code`
- 授权类型：`Authorization Code`
- 令牌身份验证：`None`（通常还要 PKCE）

没有 Client Secret，适合无法安全保存密钥的场景。本模块配置里有 Secret 字段，不走这条。

### 3. Implicit / Token 响应（过时，禁止）

- 响应类型：`Token`

Access Token 直接出现在浏览器 URL，不安全，且与「后台用 code 换 token」流程不兼容。

### 4. 纯机器间 Client Credentials

用于服务对服务、无用户登录的场景，**不是**「用户点一键授权登录 CDN」的流程。

---

## 回调 URL 怎么写

示例（把域名和后台 key 换成你的）：

```text
https://p05113ef3.weline.test:11720/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/cdn/backend/oauth/callback
```

注意：

- 必须含后台管理 key 段。
- 协议（`https` / `http`）、主机、端口、路径都必须与实际打开后台时一致。
- Cloudflare 要求 Redirect URI 精确匹配；多一个斜杠、换端口都会失败。

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
2. 换 Token 失败、提示 client 认证错误：认证方法选了 `None`，或 Secret 填错/未保存。
3. 授权成功但 CDN 接口 403：Client 勾选的 scopes 不足，或与 `oauth_scopes` 配置不一致。
4. 控制台 deep link 没进对的账户：先打开 https://dash.cloudflare.com/ 选账户，再进 OAuth clients。

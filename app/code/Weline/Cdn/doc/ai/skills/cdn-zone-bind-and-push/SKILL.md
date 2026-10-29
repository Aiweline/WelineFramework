---
name: cdn-zone-bind-and-push
description: >-
  CDN / Cloudflare 的搬迁、复制、迁移、配置拷贝都走本技能：用目标站自己的密钥封 Token，
  给根域挂一条继承默认账户的 CDN 域名，并推上 Cache Rules。
  触发：CDN 搬迁、CDN 复制、CDN 迁移、CDN 配置迁移、Cloudflare 配置复制、配置 CDN 域名、
  挂 Zone、推送缓存规则、Cache Rules 是空的。
  禁止把别的站的 secret_ref 原样当可用凭据，禁止把域名池 pool_id 写进账户。
---

# cdn-zone-bind-and-push

> **先判 lane（2026-10-29 新增）**：用户说「只配 token / 规则不用管 / 复制 cf 的配置信息 / 汉服的配置是对的」时，
> **只做 §4 封存（用目标站密钥重封凭据）就停**——不挂 `cdn_domain`、不推 Cache Rules、不改 zone 设置。
> 一键命令 + 站点矩阵 + 六条硬断言见 `websites/config-migrate/SKILL.md` §A（`scripts/cdn-token-migrate.sh`）。
> 本技能 §5 起（挂 Zone、推规则、穿章）只在用户**点名**要 Zone/Cache Rules 时执行。

把 Cloudflare 账户真正用在**这一站的根域 Zone** 上，并让 Cache Rules 出现在该 Zone。

归属：`Weline_Cdn`。机制：目标站 `SecretRefCipher` 封存账户、`cdn_domain` 继承默认账户、`RuleManager::planRulesForEdgePush` 规划后写入 Cache Rules phase entrypoint。

不要做：

- 把源站 `secret_ref` 密文原样复制后就当目标站能解密。
- 把域名池 `pool_id` 或注册商 `domain_id` 写到 CDN 账户上。
- 只登记 `www` 主机名。匹配按后缀，根域那一条才同时盖住 `www` 和根域。
- 把别的站的 Zone ID 套到本站。
- 在聊天、日志、命令行参数里打出 Token、Zone ID、`secret_ref` 正文或密钥。

## 触发词

- CDN 搬迁
- CDN 复制
- CDN 迁移
- CDN 配置迁移
- CDN 配置拷贝
- 站间复制 CDN
- Cloudflare 配置复制
- Cloudflare 配置迁移
- 配置 CDN 域名
- 挂 Zone
- 推送缓存规则
- 推送 Cache Rules
- Cache Rules 是空的
- CDN 账户有了但没推规则
- cloudflare cache rules
- cdn zone bind

话里同时有 CDN / Cloudflare 的搬迁、复制或迁移时，本技能生效。不要改走「原样复制 secret_ref」的配置迁移。同一句里的 PayPal 等非 CDN 配置仍可按配置迁移原样复制，CDN 这一段只按本技能做完。

## 先读

1. 每个目标站柜 `README.md` 与 `server.md`：主用 SSH、应用根、库、运行身份、该站 PHP。未写明线上则只做本机。
2. `app/code/Weline/Cdn/doc/Cloudflare-API-Token-Permissions.md`：Token 至少要能 `GET /zones`（Zone Read）和写 Cache Rules（Cache Rules Edit）。
3. 源码以磁盘为准：`Account::setCredentialsArray`、`Cloudflare::ensureZone`（只查找、不创建 Zone）、`RuleManager::planRulesForEdgePush`、`Cloudflare::formatRulesForApi`、`CloudflareHttpClient` 对 HTTP 错误的改写。

## 正确关系

| 对象 | 作用 |
|---|---|
| `cdn_account` | 默认 Cloudflare 账户。凭据必须是**本应用** `security.secret_ref_key` 封出来的 `secret_ref` |
| `cdn_domain` | 一条根域。`domain_name` = Zone 名（根域），`zone_id` = 该 Zone，`inherit_default=1`，`account_id` 空，`enabled=1`，`site_id` = 公开地址属于的网站 |
| 域名池 `pool_id` | 不参与推送 |
| 注册商域名 `dns_zone_external_id` | 若该根域已有值，必须与 `ensureZone` 返回的 Zone 相同，否则停 |

`website_id=0` 且 URL 是 localhost 时，不要把 CDN 域名挂到它。挂到公开 URL 属于的那个网站。

## 步骤

1. **只读盘点。** 目标站：默认 Cloudflare 账户是否存在、凭据是否 `secret_ref`、本进程能否解出非空 `api_token`（只报 yes/no）、`cdn_domain` 行、公开网站的 code/url、根域登记上是否已有 Zone。源站密文即使长度相同，解不开就不算可用。
2. **令牌。** 用能列出目标根域的 Account API Token。在目标机上请求 `GET /zones?per_page=50`，只打印目标根域在不在名单里。不在就停，不要创建 Zone。权限见权限文档。
3. **先备份再写。** 目录 `/root/backups/cdn-zone-bind-<UTC时间戳>/`，已存在就换名。至少备份将改的 `cdn_account` 行、`cdn_domain` 表，以及若会写入密钥则备份 `app/etc/env.php`。`chmod 600`，能 gzip 的做 `gzip -t` 并记下 sha256。
4. **封存。** 在目标应用进程里写 `auth_mode=token` 与 `api_token`，走 `setCredentialsArray`（它会封成 `secret_ref`）。令牌从 stdin 进进程，不要出现在 argv。
   - `app/etc/env.php` 里没有 `security.secret_ref_key` 时，先 `Env::set` 写入 32 字节随机 hex，并确认已落盘。CLI 即使 `DEV=true` 也必须先落盘真实密钥；空密钥会用开发回退钥，生产禁止。
   - 新进程再解一次，只报 `readable=yes` 和前缀是 `secret_ref`。若落成明文 JSON，停并恢复备份。
5. **挂域名。** `ensureZone(根域)`。Zone 名必须等于根域。有 `dns_zone_external_id` 则必须一致。然后插入或更新一条 `cdn_domain`（字段见上表）。不要用「从网站 URL 取 host」的命令结果当 `domain_name`，那个 host 常常是 `www`。
6. **推规则。** `planRulesForEdgePush` 后 `formatRulesForApi`，对  
   `PUT /zones/{zone_id}/rulesets/phases/http_request_cache_settings/entrypoint`  
   提交 `{ "rules": [...] }`。HTTP 200 且 `success=true` 后记下条数。
   - `pushRules` 只是入队。Weline_Cdn ≥ 1.0.54 会按 HTTP 404 + 代码 10003 认出「缺 Cache Rules 入口」，同步时自动 PUT 入口。目标站低于 1.0.54 时，队列结果会停在「Cloudflare 请求失败（HTTP 404，代码 10003）」，先按上一步建入口，再提交一次同步。
   - 入口已在时，后续同步应能 GET 到再更新。验收看队列 `accepted=true` 和 `rules_count`，以及 API 返回的规则条数。条数是 0 不算完成。
7. **收口。** 删掉含令牌的临时脚本和内存文件。聊天只报：哪些站、根域、`site_id`、是否继承默认账户、规则条数、备份路径和 sha256。不贴 Token / Zone ID。

## 验收

- 目标站新进程能解开账户，前缀是 `secret_ref`。
- `cdn_domain` 恰好覆盖该根域，`inherit_default=1`，`has_zone=yes`，`site_id` 是公开网站。
- Cloudflare 该 Zone 的 Cache Rules 条数大于 0，且与 `planRulesForEdgePush` 的条数一致。
- 同步作业不是停在 404/10003。

## 反例

| 错误 | 正确 |
|---|---|
| 把 CDN 搬迁做成原样复制 secret_ref | 按本技能在目标站重封、挂根域、推规则 |
| 把域名池 ID 写到账户上 | 新建根域 `cdn_domain`，继承默认账户 |
| 源站密文解不开仍原样写入 | 换可用 Token，用目标站密钥重封 |
| 只登记 `www` | `domain_name` 用根域 |
| 看到 404/10003 就判定 Token 无效 | 那是缺 Cache Rules 入口；PUT phase entrypoint |
| 本机 curl 公网站点探活 | 在目标机上打 Cloudflare API；探活走 SSH 上的本机回环 |

## 穿章 Bypass 规则（w_fpc_pierce）· 自动生成，禁止手写死

CDN 必须认穿章：只有带**当前**安装级 secret 的请求才跳过缓存。

- 生成：`RuleManager::getPierceBypassRules()` 按 `FpcPierceStamp::readSecret()` **现算**，
  ref `weline_cdn_fpc_pierce_bypass`，表达式 `http.request.uri.query contains "w_fpc_pierce=<secret>"`，
  action `set_cache_settings` / `{"cache":false}`。
- 位置：`planRulesForEdgePush()` 恒放第一条（CF 先匹配先生效），并从 Cache Rules 容量扣 1 条名额。
- 禁止：写进 `etc/default-rules.json`、写进 `w_cdn_api_rule`、或在上线脚本里硬编码 secret——换章即失真。
- 换章：`FpcPierceStampAdminService::rotateAndPublish()` / `FullPageCacheAdminService::rotatePierce()`
  → `PierceRuleSyncService::pushAll()` → `requestManualSync` → 队列 `cdn-fpc-sync` → 写 CF。
- 验证别用 HTML：被打穿响应带 `Set-Cookie`，`cf-cache-status: BYPASS` 是副产物；
  用静态可缓存资源（`/sitemap.xml`）：正确章 `BYPASS`、错误章不得 `BYPASS`。

排查顺序（2026-10-26 线上实测顺序）：`getPierceBypassRules()` 有 1 条 → `planRulesForEdgePush()[0].ref` 是它 →
`getCredentials($domain)` 非空 → 队列 `accepted=true`。第二步就空 = 账户密文解不开（见下），不是规则代码问题。

## 凭据解不开：诊断与处置

`w_cdn_account.credentials` 是 `secret_ref:v1o:`（`SecretRefCipher` + `security.secret_ref_key`）。
跨站搬 `secret_ref` 会在本站解密失败：`revealJson()` 抛 `secret_ref_decrypt_failed`，`getCredentials()` 返回 `[]`，
作业停在 `cloud: cdn_fpc_credentials_unavailable`（purge 同样拿不到令牌）。

处置：在本站后台重新录入 Token（重新密封）；或恢复原 `security.secret_ref_key`。改规则代码绕不过去。

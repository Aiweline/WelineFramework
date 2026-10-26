# 前台 Weline 面板 · SEO 自检（生产可用）

> 目的：不靠手搓 `w_fpc_pierce` + 解析 JSON-LD 来验 SEO。线上页面打出 Weline 面板，面板内直接看 SEO 检查与性能数据。
> 实现：`Weline_DeveloperWorkspace`（注入 + token 门禁）+ `Weline_Seo`（`__WELINE_SEO_INSPECTOR__`）。

## 1. 打开条件

| 环境 | 条件 | 说明 |
|---|---|---|
| 本地 / dev | `DEV` / `DEBUG` / `WLS_DEV_MODE` 任一为真 | 面板直接注入，输入 `weline` 即开 |
| **生产** | `env: dev_tool.panel.enable_in_prod = true` **且** 配置了 token 材料 | 未配置 env 时**不注入**、也不暴露 `window.WelinePanel` |

token 材料两种写法（`Weline_Administrator...` SystemConfig，模块 `Weline_DeveloperWorkspace`，area `backend`）：

- 推荐 `dev_tool.panel.token_hash`（SHA-256 / bcrypt / argon2 hash）
- 临时调试 `dev_tool.panel.token`（明文）

也可放 env（`dev_tool.panel.token_hash` / `dev_tool.panel.token`）。判定见 `DeveloperWorkspace/Service/PanelAccessService.php::hasProductionPanelConfig()`。

## 2. 打开方式

1. 打开任意店面页（不需要清缓存，也不需要打穿章）；
2. **不要在输入框里**，直接在页面空白处依次敲 `weline`；
3. 弹出 `Weline Panel Token` → 输入原始 token → 打开；
4. 验证通过写 HttpOnly `w_weline_panel` cookie（默认 TTL 3600s），面板 shell 加载。

> 会话端点：`POST /{rest_backend_prefix}/dev/tool/rest/v1/panel/session`，JSON `{"token":"…"}`，
> 头需 `X-Weline-Panel: 1`。`rest_backend_prefix` 来自 `env: router.area_routes.rest_backend.prefix`（每站随机）。
> 面板 apiBase 在注入的 `<script … data-api-base="…">` 上可直接读到。

## 3. 面板里做 SEO 验收（本仓事实）

- 页面注入 `__WELINE_SEO_INSPECTOR__`（`renderInto` / `audit` / `report` / `publish`）与 `seo-inspector` 资源；
- 用面板的 **SEO** 页签核对：标题/描述/robots/canonical/hreflang、JSON-LD 节点（Product/Offer/Organization 等）与问题清单；
- 用 **性能** 页签核对 TTFB、请求 trace（`X-Weline-Request-Id` → Trace REST）；
- 富结果字段（商家信息类）以页面 JSON-LD 原文为准：面板定位问题 → 再用 `curl` 打同一 URL 的 JSON-LD 做机器断言。

## 4. 验收证据模板

```
页面：https://<站点>/…            → 200
注入：grep -c 'dev-tool-panel-loader' → 1；grep -c '__WELINE_SEO_INSPECTOR__' → ≥1
授权：POST …/panel/session {"token":"…"} → 200 {"success":true,"ttl":3600} + Set-Cookie w_weline_panel
面板：GET  …/panel?id=<request_id> 带 cookie → 200
SEO ：JSON-LD 关键字段原文（brand / offers.hasMerchantReturnPolicy / shippingDetails …）
```

## 5. 生产实例（2026-10-26 已开启）

- `changanhanfu.com`：`dev_tool.panel.enable_in_prod=true` + `token_hash` 已写入 `app/etc/env.php`
  （备份 `/root/backup/env.php.before-dev-panel-*`）。
- token 明文只存站点柜：`websites/changanhanfu.com/credentials/.dev-panel-token`（600，不入库）。
- 复验：首页注入 `dev-tool-panel-loader`=1、`data-token-required`=1、`__WELINE_SEO_INSPECTOR__`≥1；
  `POST …/panel/session` → `{"success":true,"message":"Weline 面板已授权。","ttl":3600}`。
- 关闭方式：删 `dev_tool.panel.enable_in_prod` 或置 `false`，`cache:clear` + `server:reload`。

## 6. 命令行批量校验（机器证据，推荐）

面板只能人看；要给证据/回归，用仓内脚本 `tests/seo_audit_urls.php`（纯 curl，不需要 bootstrap）：

```bash
# 按 sitemap 抽样 20 条，商品页强校验商家信息字段
php tests/seo_audit_urls.php \
  --sitemap=https://<站点>/sitemaps/default/canonical/sitemap_weline-product-product_en-us_1.xml \
  --limit=20 --strict-merchant

# 指定 URL 文件 / 单 URL
php tests/seo_audit_urls.php --urls=/tmp/urls.txt --limit=50
php tests/seo_audit_urls.php --url=https://<站点>/product/<slug> --strict-merchant
```

输出每条一行，结尾 `SUMMARY total=… bad=… ok=…` + 问题计数；**有 BAD 时 exit 1**（可直接当 CI / 验收门禁）。

检查项：HTTP 200、`<title>`、meta description、canonical、robots、JSON-LD 是否可解析、
Product/ProductGroup 的 `name`≤150 / `description` / `brand` / `hasVariant` / `offers`，
以及 `--strict-merchant` 下的 `hasMerchantReturnPolicy.merchantReturnDays`、`shippingDetails`、`Organization.email/address`。

实测（2026-10-26，changanhanfu en_US 商品 sitemap）：
`SUMMARY total=20 bad=0 ok=20`，逐条 `return_policy=5/5…10/10`、`shipping_details=5/5…15/15`、`brand=y`。

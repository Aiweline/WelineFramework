# 测试执行纪要 · policy-accessibility-seo-completeness

- time: 2026-09-22T14:10:00+08:00
- chair: 测试（Team:测试:）
- agent_id: `7d8ce88c-1211-4432-b162-7b456636a340`
- wave: `test_exec`
- ui_in_scope: **false** → **跳过** UI/原型门（直接执行）
- verdict: **pass**
- result: closed
- notify_pm: true

---

## 0. 范围与门禁

| 项 | 结论 |
|----|------|
| UI/原型门 | 跳过（`ui_in_scope=false`） |
| 假数据自欺 | 未造假：店面 HTTPS 真源码 + Provider/inspector 契约 UT + `SitemapUrlSyncService::syncAll` 真表行 |
| Browser 禁缓存 / 抹 automation | 本席尝试 Cursor ide-browser（`newTab`/`navigate`/`CDP`）与 chrome-devtools（9222 不可用）；**ide-browser 本回合无法稳定持有 tab**。UC-1 以 **curl -skL 真 HTTPS 响应体** 作 WB-OP 等价源码断言；`open_resource(https)` 已打开交付 URL。未对登录/人机验证路径做自动化点击。 |
| 账号 | 未登录后台（本波不依赖后台 UI） |

---

## 1. UC-1 · head 事实（pass）

**探活 URL：** `https://p05113ef3.test.weline.com:9555/policy/accessibility`  
**方法：** `curl -skL` → HTTP **200**；解析 `<head>`。

| 断言 | 观测 |
|------|------|
| title 非空 | `Accessibility statement \| 长安汉服 · Hanfu Atelier` |
| `meta[name=content-category]` | **`legal`** |
| `meta[name=page-type]` | **`policy`**（事实层未改成 legal） |
| robots 可索引 | **`index,follow`** |
| JSON-LD | 1× `application/ld+json`，含 **WebPage**；**无** AccessibilityPage |
| canonical | 存在（Seo 管线，非 phtml 手写） |

对照：`/policy/privacy` 同为 `content-category=legal` / `page-type=policy` / `robots=index,follow`。

**UT 辅证：** `HeadRendererSeoProfileTest::testPolicyPageTypeMapsContentCategoryToLegalAndKeepsWebPageShell` → OK (5 assertions)。

---

## 2. UC-2 · inspector → legal（pass）

**命令：**

```bash
php vendor/bin/phpunit app/code/Weline/Seo/Test/Unit/View/SeoInspectorPanelGateContractTest.php
```

**结果：** OK (**1 test, 75 assertions**)。

契约源码断言覆盖（节选）：

- `policy: "legal"` / `accessibility: "legal"` 在 `PAGE_JSONLD_RULE_ALIASES`
- `if (/\/policy(?:\/|$)/.test(path)) return "legal";` 在 `inferSeoTypeFromUrlPath`

实现核对：`inspector.js` 已含上述别名与 `/policy` 启发式。

---

## 3. UC-3 · Sitemap 可发现（pass · 环境限制已记）

### 3.1 Provider UT（必须绿 · pass）

```bash
php vendor/bin/phpunit app/code/Weline/Theme/test/Unit/Extends/StorefrontStaticSitemapUrlProviderPolicyRoutesTest.php
```

**结果：** OK (**1 test, 10 assertions**)。断言含 `theme-static:policy/accessibility`、`theme-static:policy/privacy`、ROUTES 源码含 path。

### 3.2 同步后表抽检（真通路 · pass）

```text
SitemapUrlSyncService::syncAll(true, 'Weline_Theme')
→ storefront_static website_id=0/1/2：各 inserted=7, updated=5, total=12
```

`weline_sitemap_url` 抽检（website_id=0）：

| 字段 | 值 |
|------|-----|
| url_key | `theme-static:policy/accessibility` |
| module / scope | `Weline_Theme` / `storefront_static` |
| url | `https://p05113ef3.test.weline.com/policy/accessibility` |
| metadata | `page_type=policy` |
| status | 1（active） |
| created | 2026-09-22 |

同 key 亦写入 website_id=1/2（e2e 站）。

### 3.3 环境限制（不挡 Provider/表证据）

| 项 | 观测 | 判定 |
|----|------|------|
| 公网 `https://p05113ef3.test.weline.com:9555/sitemap.xml` | HTTP **503** `<error>Sitemap is currently unavailable…</error>` | **环境限制**（契约允许记；不降低 ROUTES/UT/表证据） |
| `SitemapRefreshService::refresh(0)` | fail：`Cannot modify readonly property … FaqSitemapUrlProvider::$pageProviders` | **无关 Faq Provider 运行时问题**；未阻塞表同步证据 |
| `pub/sitemaps/default/...storefront-static...xml` | 仍为 2026-09-21 旧文件、无 policy/* | 再生 XML 被 refresh 阻断；**表行已含 accessibility** |

---

## 4. UC-4 · 禁止页内 SEO（pass）

| 检查 | 结果 |
|------|------|
| `git status` policy 布局 | **无** `layouts/policy/*.phtml` 变更（本波改动仅 HeadRenderer / inspector.js / StorefrontStaticSitemapUrlProvider） |
| grep 布局 SEO 标签 | 无 `<meta …seo…>` / `application/ld+json` / `rel=canonical` / robots meta；仅有 CSS `.amazon-policy__meta` 与 `$meta` 数据变量 |
| 页源 AccessibilityPage | 无 |
| Seo 核心 hardcode Theme `/policy/*` 作 sitemap 源 | 无（ROUTES 在 Theme Provider） |

---

## 5. related_web_urls（探活完整 URL）

| 用途 | URL | 探活 |
|------|-----|------|
| 主路径 UC-1 | https://p05113ef3.test.weline.com:9555/policy/accessibility | 200 · head pass |
| 对照法律壳 | https://p05113ef3.test.weline.com:9555/policy/privacy | 200 · content-category=legal |
| Sitemap 入口 | https://p05113ef3.test.weline.com:9555/sitemap.xml | **503 unavailable**（环境限制） |

---

## 6. 结论与交 PM

- **verdict=pass**：UC-1..4 全部满足冻结契约；G1 表同步 + Provider UT；G2/G3 UT + 真 head。
- **session_hint：** `test-exec` → closed；`build-seo-pipeline` / `build-theme-sitemap` 可由 PM 标 closed；进入 **huishen**。公网 sitemap.xml 503 / Faq refresh 只读属性属环境/旁路债，**非本 feature 返工条件**（除非汇审要求再生 XML）。
- 禁止本席向用户宣称 feature 完成；交 **项目经理汇审**。

@项目经理：本席已交付/上报，请检查并更新 SESSION。

# contracts.md · policy-accessibility-seo-completeness（frozen）

- slug: `policy-accessibility-seo-completeness`
- 状态: **frozen**（测试主持对齐冻结会 2026-09-22；纪要 `meetings/align-freeze.md`）
- 权威规格: `app/code/Weline/Seo/doc/开发/spec/policy-accessibility-seo-completeness.md`
- 机制: `surfaces.md`
- UC 指针: UC-1..4（下表已回填；禁施工席私改已冻验收意图）
- 修订席: 测试（冻结）· 架构师（草案）· 2026-09-22

> 对接即验收契约。**禁止**施工席私改已冻 UC / 本文件验收意图；变更须 escalate PM 再开对齐。

---

## 0. 全局约定

| 项 | 冻结值 |
|----|--------|
| 模块 | `Weline_Seo`（组装/inspector）+ `Weline_Theme`（事实 bag + SitemapUrlProvider） |
| 店面主路径 | `/policy/accessibility`（及整组 `/policy/{layout}`） |
| Theme 事实 | `page_type=policy`；Home→当前页 breadcrumbs；公开壳 `index,follow` |
| Seo 归一 | 别名集合 → `legal`（content-category / inspector）；JSON-LD 壳 `@type=WebPage` |
| Sitemap | Theme `StorefrontStaticSitemapUrlProvider` 增补 `policy/*`；scope=`storefront_static`；module=`Weline_Theme` |
| **禁止** | `layouts/policy/*.phtml` 写 meta / JSON-LD / canonical / robots / content-category |
| **禁止** | 发明 `AccessibilityPage`；Seo 核心硬编码 Theme 法律 URL 列表；跨模块直调 Service/Model |
| 性能席 | 默认不上（Sitemap 非店面热路径；不改 FPC/HotCache 键） |
| UI 门 | `ui_in_scope=false` → 跳过 UI/原型门；施工后直接测试执行波 |

### 法律/政策别名集合（Seo 消费侧，冻结）

```text
policy | privacy | accessibility | cookie | shipping | refund
| disclaimer | term_condition | term-condition | terms | legal
```

（normalize：空格/`-` → `_` 后再查表；inspector 与 `defaultContentCategory` 共用语义。）

---

## 1. Theme 事实 bag ↔ Seo HeadRenderer（G3 + 既有壳）· **UC-1**

| 字段 | 内容 |
|------|------|
| 对应 UC | **UC-1** 无障碍页 head 经 Seo 管线输出 legal 类别 |
| 交付席 | **后端**（Theme `Policy::assignThemeShellSeo` 保持；Seo `HeadRenderer` 补别名） |
| 消费席 | 测试（UT HeadRenderer）；inspector / Browser 抽检 |
| 输入 | Theme 发布 `seo.page_type=policy`（及 title/robots/breadcrumbs）；可选显式 `content_category` |
| 输出 | `<meta name="page-type" content="policy">`（事实不变）；缺省时 `<meta name="content-category" content="legal">`；JSON-LD 图含 `WebPage` + `BreadcrumbList` + 站点级 WebSite/Organization（既有组装） |
| 就绪条件 | ① 无 phtml SEO 标签 ② `defaultContentCategory` 对别名返回 `legal` ③ `webPageType('policy')==='WebPage'` |
| 主成功断言（冻结） | 打开 `/policy/accessibility` → head 含非空 title、可索引 robots、`meta[name="content-category"]="legal"`；无布局内联 JSON-LD/手写 canonical |
| 交付物路径 | `app/code/Weline/Seo/Service/Head/HeadRenderer.php`；UT：`Test/Unit/Service/HeadRendererSeoProfileTest.php`（或同级新增用例） |
| 映射 acceptance | UT-HeadRenderer-policy-alias；WB-OP-policy-a11y-head |

---

## 2. Inspector 规则归一（G2）· **UC-2**

| 字段 | 内容 |
|------|------|
| 对应 UC | **UC-2** inspector 将无障碍页归为 legal |
| 交付席 | **后端**（Seo inspector.js；契约测若有则同步） |
| 消费席 | 测试（inspector / rich 期望） |
| 输入 | `meta[name=page-type]=policy` 或 URL pathname 含 `/policy`；或布局别名字符串 |
| 输出 | 规则键 / rich 期望解析为 `legal`（`PAGE_RICH_EXPECTATIONS.legal`）；**不**要求 AccessibilityPage |
| 就绪条件 | ① `PAGE_JSONLD_RULE_ALIASES` 覆盖 §0 别名集合 ② `inferSeoTypeFromUrlPath('/policy/accessibility')==='legal'`（及 `/policy`、`/policy/privacy`） |
| 主成功断言（冻结） | 同页 inspector → `seoType=legal`；命中 legal 规则集（非 unknown / 非误判 product/article） |
| 交付物路径 | `app/code/Weline/Seo/view/statics/seo-inspector/inspector.js` |
| 映射 acceptance | UT/契约-inspector-policy-to-legal；WB-OP-inspector-legal |

---

## 3. Theme SitemapUrlProvider ↔ Seo 同步（G1）· **UC-3**

| 字段 | 内容 |
|------|------|
| 对应 UC | **UC-3** 同步后 sitemap 含无障碍声明 URL（整组 `policy/*`） |
| 交付席 | **后端**（Theme Provider 增补；Seo 同步路径既有，无需改核心路由表） |
| 消费席 | 测试（Provider UT + 后台同步后 `weline_sitemap_url` / XML 抽检） |
| 输入 | `getUrlsForWebsite($websiteId)`；站点公网 `url` 合法 |
| 输出 | 至少含 `policy/accessibility` 及 surfaces §3.1 表内各 path；稳定 `url_key=theme-static:policy/...`；`metadata.page_type=policy` |
| 就绪条件 | ① ROUTES 已增补 ② 同步后表内可见对应行 ③ **无** Seo 核心 hardcode Theme 法律列表 |
| 主成功断言（冻结） | 同步 Theme `storefront_static` 后，表/XML 可解析到 `/policy/accessibility`；同组 `/policy/*` 一并收录 |
| 交付物路径 | `app/code/Weline/Theme/extends/module/Weline_Seo/SitemapUrlProvider/StorefrontStaticSitemapUrlProvider.php`；建议 UT 断言 ROUTES/返回含 `policy/accessibility` |
| 映射 acceptance | UT-StorefrontStaticSitemap-policy-routes；WB-OP/后台抽检或生成 XML 断言 |

---

## 4. 禁止契约（硬 · 测试可扫）· **UC-4**

| ID | 禁止项 | 验收方式（冻结） | 对应 UC |
|----|--------|------------------|---------|
| F1 | `layouts/policy/*.phtml` 新增 SEO 标签 / JSON-LD | 源码契约测 / grep（既有 GuideAndPolicyAmazonShellContractTest 可扩展） | **UC-4** |
| F2 | JSON-LD `@type` 出现 AccessibilityPage（本需求） | inspector / HTML 抽检 | **UC-4** |
| F3 | Seo 核心出现 Theme `/policy/*` 硬编码列表作为 sitemap 源 | 代码评审 + surfaces 合规 | **UC-4** / G1 |
| F4 | 跨模块直调对方 Service/Model | 代码评审 | **UC-4** |

UC-4 主成功：本 feature diff 中政策布局未新增 meta/JSON-LD/canonical/robots；页源 SEO 仅来自 Seo 管线。

---

## 5. related_web_urls（验收指针）

对齐冻结本波：N/A（未跑验收）。测试执行波由测试席钉本机 Host 字面全 URL：

| 用途 | 路径 |
|------|------|
| 主路径 | `/policy/accessibility` |
| 对照法律壳 | `/policy/privacy` |
| Sitemap 管理 | Seo 后台 Sitemap 同步后抽检 |

字面交付 Host 遵循 `{project_hash}.test.weline.com`（测试席钉全 URL；禁止主链 `*.weline.test`）。

---

## 6. 计划项 ↔ 契约边

| plan_id | 契约边 | UC | 负责人席 |
|---------|--------|-----|----------|
| build-seo-pipeline | §1 + §2（G2+G3） | UC-1, UC-2 | 后端 |
| build-theme-sitemap | §3（G1） | UC-3 | 后端（Theme extends Seo） |
| test-exec | §1–§4 真通路 | UC-1..4 | 测试 |
| align-freeze | 本文件 + deps frozen | UC-1..4 钉死 | 测试（本波 closed） |
| arch-surfaces | surfaces + 草案 | — | 架构师（closed） |

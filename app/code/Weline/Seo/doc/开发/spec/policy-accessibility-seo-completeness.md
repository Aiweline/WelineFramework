---
status: ready-for-align
work_kind: feature
feature_slug: policy-accessibility-seo-completeness
module: Weline_Seo
updated: 2026-09-22
session_path: ../session/policy-accessibility-seo-completeness.md
fe_be_scope: Seo 管线（HeadRenderer / inspector）+ Theme→Seo SitemapUrlProvider 扩展；非 UI 改版
ui_in_scope: false
plan_complexity: simple
clarify_source: host-Read 需求澄清与用例规格.md（get_skill DISABLED）
---

# 无障碍声明（政策壳）SEO 完整度补齐

## 澄清记录

| # | 问题 | 结论 |
|---|------|------|
| 1 | 本波改什么？ | 仅补 Seo 管线缺口 G1–G3：sitemap 可发现、inspector 归类为 `legal`、HeadRenderer `content-category` 别名。不改政策正文/合规文案。 |
| 2 | 是否改 `layouts/policy/*.phtml` 视觉或正文？ | **否**。`ui_in_scope=false`。禁止在政策页 phtml 手写 meta / JSON-LD / canonical / robots。 |
| 3 | 页面事实谁提供？ | Theme 控制器继续 `assignThemeShellSeo` 发布 `page_type`（当前为 `policy`）、`robots`、`breadcrumbs` 等**事实**；类型归一与 `content-category` 组装归 `Weline_Seo`。 |
| 4 | Sitemap 落点？ | Theme 已有 `StorefrontStaticSitemapUrlProvider`（`SitemapUrlProvider` 扩展点）；补录整组 `/policy/*`（至少含 `/policy/accessibility`），由 Seo `SitemapUrlSyncService` 同步，禁止业务直写 sitemap 表。 |
| 5 | Inspector 期望类型？ | 法律/政策页规则集为 `legal`；须把 `page_type=policy`（及 privacy 等已有别名同类）alias→`legal`，并对 URL 含 `/policy/` 做启发式，使 `/policy/accessibility` 判为 `legal`。 |
| 6 | HeadRenderer `content-category`？ | 今日 `defaultContentCategory` 仅认归一后的 `legal`；须认 `policy` / `accessibility` / `privacy`（及同类政策别名）→ 输出 `content-category=legal`。 |
| 7 | 是否需电商顾问？ | **否（本波）**。无政策正文、运费/退换货/支付政策文案、首页落地页运营设计变更；仅 SEO 管线。见「顾问约束」。 |
| 8 | 验收形态？ | 非 UI 改版：可跳过 UI/原型门；须 UT + 真通路（head 源码 / inspector / sitemap 同步后抽检）。后台账号默认 admin/admin（本席不测）。 |

## 顾问约束

**N/A（无正文变更）**

理由：本 feature 不改 `/policy/*` 政策正文、合规文案、国家政策联网结论、商品/购物车/结账/订单/支付/运费/促销/退换货运营设计；只在既有 Theme 政策壳事实之上补齐 Seo 组装与发现。立项波**不**并行电商顾问；若后续波次改政策正文或合规面，须重新 `ask` 电商顾问并阻断冻结直至表态。

## 目标 / 非目标

**目标**

- `/policy/accessibility`（及整组已公开 `/policy/*`）经 Seo 管线输出正确 head 事实（含 `content-category=legal`），且无页内手写 SEO。
- Seo inspector 将该页判定为 `legal` 规则集。
- XML Sitemap 同步后可发现 `/policy/accessibility`（及同组政策静态路由）。
- 继续框架扩展点：事实归 Theme bag / SitemapUrlProvider；组装归 Seo。

**非目标**

- 不改政策页视觉、布局结构、正文 HTML、i18n 文案内容。
- 不在 `layouts/policy/*.phtml`（含 `accessibility.phtml`）新增 meta / JSON-LD / canonical / robots。
- 不新建 SeoProfileProvider / 不重做 `<w:seo>` Taglib。
- 不改商品/CMS/博客实体 SEO；不改支付、结账、运费政策业务逻辑。
- 不做站外提交平台适配器变更（`SitemapAdapter` / `SearchEngineAdapter` 非本期）。

## work_kind / 范围

| 字段 | 值 |
|------|-----|
| work_kind | `feature` |
| fe_be_scope | **后端/管线**：`Weline_Seo` HeadRenderer + inspector；**Theme→Seo 扩展**：`StorefrontStaticSitemapUrlProvider` 补录 policy 路由 |
| UI in_scope | `false`（不改视觉；非 UI 可跳过 UI/原型门） |
| 计划复杂度 | `simple`（机制已明确，对齐冻结后直达实现） |

## 角色

- 搜索引擎 / 爬虫：经 sitemap 与 head 发现并理解政策页类型。
- 运营 / SEO 运营：在后台同步 Sitemap 后可抽检收录。
- 开发（后端）：按扩展点补缺口，禁止页内 SEO。
- 测试：UT + 真通路（head / inspector / sitemap）。

## 用户故事

As a SEO 运营, I want `/policy/accessibility` 出现在同步后的 sitemap 中, so that 搜索引擎能发现无障碍声明页。

As a SEO 运营, I want 无障碍页 head 输出正确的 `content-category`（legal）等事实, so that 页面类型与法律壳一致且不依赖模板手写。

As a SEO 运营, I want Seo inspector 将政策页判定为 `legal`, so that 法律/政策规则集（WebPage + BreadcrumbList 等）对本页生效。

As a 框架开发者, I want 政策页继续只发布事实、由 Seo 组装, so that 不违反「业务模板不手写 JSON-LD/meta」规约。

## 验收标准（EARS）

1. WHEN 访客打开店面 `/policy/accessibility` THEN 系统 SHALL 在文档 head（经 `<w:seo>` / HeadRenderer 管线）输出与法律壳一致的可观察 SEO 事实，至少含非空 title、可索引 robots（`index,follow` 类）、以及 `meta[name="content-category"]` 值为 `legal`。
2. IF Theme 控制器对政策页发布 `page_type=policy`（或 URL 路径为 `/policy/accessibility`）且未显式覆盖 `content_category` THEN HeadRenderer SHALL 将归一后的类型映射为 `content-category=legal`（认 `policy` / `accessibility` / `privacy` 等别名，不仅认字面 `legal`）。
3. WHEN 在 `/policy/accessibility` 加载 Seo inspector THEN inspector SHALL 将页面归类为 `seoType=legal`（含 `page_type=policy` alias 与 URL `/policy/` 启发式），并按 `legal` 规则集评估，不得因未识别类型落到无关规则。
4. WHEN 运营在 SEO Sitemap 管理执行「同步所有 Provider」（或等价 cron/`SitemapUrlSyncService` 同步 Theme `storefront_static` Provider）THEN 系统 SHALL 在 `weline_sitemap_url`（或生成的 sitemap XML）中出现可解析为 `/policy/accessibility` 的 `loc`（同源相对或绝对均可）。
5. WHILE 实现与验收本 feature THEN 系统 SHALL NOT 在 `layouts/policy/*.phtml`（含 `accessibility.phtml`）新增或保留手写 meta / JSON-LD / canonical / robots 标签；SEO 输出仅经 Seo 管线。
6. IF 站点未配置可用公网 `website.url` 导致静态 Provider 返回空列表 THEN 系统 SHALL 不伪造 sitemap 行；验收前置须保证目标站有有效 base URL（测试环境按本机交付 Host 约定）。

## 隐形需求摘要

- 复用既有 `<w:seo>`、`HeadRenderer`、`assignThemeShellSeo`、Theme `StorefrontStaticSitemapUrlProvider`；扩展点选型见下，不跨模块直写 Seo 表。
- 整组 `/policy/*`（cookie / privacy / term-condition / refund / disclaimer / shipping / accessibility 等白名单布局）与无障碍页同等发现义务；本规格主路径以 accessibility 为锚，同步范围须覆盖整组。
- 契约测：HeadRenderer UT（content-category 别名）；inspector 契约/规则测（policy→legal）；Provider UT（ROUTES 含 policy 路径）。
- 非 UI：对齐冻结后可标跳过 UI/原型门；仍须真通路证据。

## 框架机制映射（澄清层 · what，非 how 补丁步骤）

| 缺口 | 意图归类 | 优先机制 | 归属 | 禁止 |
|------|----------|----------|------|------|
| G1 发现 URL | 读/上报可索引 URL | **SitemapUrlProvider**（Theme 扩展 Seo） | `Weline_Theme` → `extends/module/Weline_Seo/SitemapUrlProvider` | 直写 `weline_sitemap_url`；在 phtml 塞 sitemap 链接当「收录」 |
| G2 inspector 类型 | Seo 域组装/诊断 | Seo 模块内 inspector 归一（alias + URL 启发式） | `Weline_Seo` | 业务模板写隐藏 meta 骗过 inspector |
| G3 content-category | Seo head 组装 | HeadRenderer 默认类别映射 | `Weline_Seo` | 模板手写 `<meta name="content-category">` |
| 页面事实 | 控制器 bag | Theme `assignThemeShellSeo` 继续发布 `page_type`/breadcrumbs | `Weline_Theme` Policy 控制器 | 把类型归一逻辑塞进 phtml |

权威文档指针（实现席只读）：`扩展规约说明.md`、`Sitemap扩展开发指南.md`、`SEO结构化数据说明.md`（业务模块不手写 JSON-LD）、`扩展点选型.md`。

## 用例

### UC-1 无障碍页 head 事实正确（主路径）

| 字段 | 内容 |
|------|------|
| id | UC-1 |
| 名称 | 无障碍页 head 经 Seo 管线输出 legal 类别 |
| 角色 | 访客 / SEO 运营 |
| 前置 | 本机店面可访问 `/policy/accessibility`；Theme Policy 仍发布 `page_type=policy` 与 shell SEO bag；未在政策 phtml 手写 SEO |
| 主成功步骤 | 1. 打开 `/policy/accessibility` 2. 查看页面源码 head 3. 断言存在 Seo 管线产出的 title / robots / `meta[name="content-category"]="legal"`（及既有 page-type 等事实，以实现后契约为准） 4. 确认 head 中无布局文件内联的 JSON-LD script 或手写 canonical 块 |
| 备选/异常 | 若布局显式 `meta_title`/`meta_description` 有值，仍经布局 SEO 兜底桥进入 bag，不改为页内硬编码标签 |
| 期望结果 | head 事实正确且 `content-category=legal`；无页内 SEO |
| 映射 acceptance | UT-HeadRenderer-policy-alias；WB-OP-policy-a11y-head（或等价源码断言） |

### UC-2 Inspector 判为 legal（主路径）

| 字段 | 内容 |
|------|------|
| id | UC-2 |
| 名称 | inspector 将无障碍页归为 legal |
| 角色 | SEO 运营 / 测试 |
| 前置 | UC-1 页可打开；Seo inspector 静态资源可用 |
| 主成功步骤 | 1. 打开 `/policy/accessibility` 2. 启用/打开 Seo inspector 3. 读取推断的 `seoType`（或等价诊断字段） 4. 确认命中 `legal` 规则集（非 unknown / 非误判为 product/article） |
| 备选/异常 | 仅 URL 为 `/policy/...` 而 bag 暂缺 page_type 时，URL 启发式仍应归为 `legal` |
| 期望结果 | `seoType=legal`；法律/政策规则参与评估 |
| 映射 acceptance | UT/契约-inspector-policy-to-legal；WB-OP-inspector-legal |

### UC-3 Sitemap 可发现 /policy/accessibility（主路径）

| 字段 | 内容 |
|------|------|
| id | UC-3 |
| 名称 | 同步后 sitemap 含无障碍声明 URL |
| 角色 | SEO 运营 |
| 前置 | 目标 website 具有效公网或本机验收 base URL；后台可登录（默认 admin/admin，本席不测） |
| 主成功步骤 | 1. 进入 SEO 管理 → Sitemap 管理 2. 执行「同步所有 Provider」 3. 在 URL 表或生成的 sitemap XML 中检索 `/policy/accessibility` 4. 确认 `url_key` 稳定（建议 `theme-static:policy/accessibility` 或等价）且 module/scope 归属 Theme storefront_static |
| 备选/异常 | website.url 无效时 Provider 返回空 → 验收环境先修好站点 URL，不降低本需求 |
| 期望结果 | `/policy/accessibility` 可被发现；同组 `/policy/*` 一并收录 |
| 映射 acceptance | UT-StorefrontStaticSitemap-policy-routes；WB-OP/后台抽检或生成 XML 断言 |

### UC-4 禁止页内 SEO（负面主路径 / 门禁）

| 字段 | 内容 |
|------|------|
| id | UC-4 |
| 名称 | 政策 phtml 不引入页内 SEO |
| 角色 | 架构 / 测试 / 代码审查 |
| 前置 | 实现 diff 与 `layouts/policy/*.phtml` 可审查 |
| 主成功步骤 | 1. 审查本 feature 相关 diff 2. 确认政策布局未新增 meta/JSON-LD/canonical/robots 3. 打开 `/policy/accessibility` 源码确认 SEO 标签仅来自 Seo 管线 partial/hook |
| 备选/异常 | 无 |
| 期望结果 | 门禁通过；与 SESSION「无政策页 phtml 新增 SEO」一致 |
| 映射 acceptance | 代码审查清单 + WB 源码抽检；汇审项 |

## 将产生的验收意图（acceptance）

| 类型 | 意图 id（建议） | 覆盖 |
|------|-----------------|------|
| UT | UT-HeadRenderer-policy-alias | G3 |
| UT / 契约 | UT-inspector-policy-to-legal | G2 |
| UT | UT-StorefrontStaticSitemap-policy-routes | G1 |
| 真通路 | WB-OP-policy-a11y-head / inspector / sitemap 抽检 | UC-1..3 |
| e2e-plan-suite | 非 UI 简单 feature：以 UT+真通路专章收口；若团队要求全路径套件则挂 accessibility SEO 专章 | 对齐冻结钉死 |

## 就绪检查

- [x] `status` = `ready-for-align`（可进对齐冻结；等价于 ready-for-plan 且团队波次对齐）
- [x] ≥1 用户故事 + 每故事对应 EARS（上文 ≥6 条）
- [x] ≥1 主路径用例（UC-1..3）+ 禁止页内 SEO（UC-4）
- [x] 非目标明确；UI in_scope=false
- [x] feature 已点名 UT / 真通路验收意图
- [x] 正文聚焦 what；机制映射仅点名扩展点与归属，不写补丁步骤
- [x] 顾问约束已声明 N/A 及理由

## 下一步（交项目经理）

1. 与架构师 `surfaces` 对齐：G1–G3 机制与本规格无冲突。  
2. 测试主持对齐冻结会：钉死 UC-1..4 + contracts + deps。  
3. 冻结后方可后端实现；本席不写 PHP/phtml/CSS/JS。

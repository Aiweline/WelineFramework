# surfaces.md · policy-accessibility-seo-completeness

> 机制落点表。对照 `Framework/doc/3-开发/扩展点选型.md`、`Seo/doc/扩展规约说明.md`、`Sitemap扩展开发指南.md`、`SEO结构化数据说明.md`。  
> 席位：架构师 · 2026-09-22 · 状态：**已冻结**（对齐冻结会勾选 §7；纪要 `meetings/align-freeze.md`）

## 0. 选型合规总览

| 意图 | 选用机制 | 状态 | 禁止 |
|------|----------|------|------|
| 政策页 SEO 组装（meta / JSON-LD / robots / content-category） | **Weline_Seo `HeadRenderer`**（消费 Theme bag 事实） | ✅ 既有；补别名 | 在 `layouts/policy/*.phtml` 写 SEO；跨模块直调 Seo Service |
| 政策页页面事实（`page_type` / breadcrumbs / robots） | Theme 控制器 bag（`Policy::assignThemeShellSeo`） | ✅ 既有；保持 | 把 `page_type` 改成 `legal`（事实层保持 `policy`） |
| 可索引 URL 上报（G1） | **SitemapUrlProvider**（Theme extends Seo） | ✅ 既有 Provider 增补 | Seo 核心硬编码 Theme 法律页列表；phtml 写 sitemap |
| Inspector 规则归一（G2） | Seo 静态 inspector（别名 + URL 启发式） | ✅ 既有；补 alias/路径 | 发明 `AccessibilityPage` 期望 |
| content-category 默认值（G3） | `HeadRenderer::defaultContentCategory`（别名→`legal`） | ✅ 既有；补分支 | 用 `SeoProfileProvider` 仅为别名绕路 |
| 自定义 schema / slot | **skip** | — | 本需求无额外节点；禁 `AccessibilityPage` |
| Event / QueryProvider / Widget | **skip** | — | 非本缺口意图 |

**框架优先结论：** 不新建扩展点。G1 落 Theme 已有 `SitemapUrlProvider`；G2/G3 落 Seo 模块内规范化；事实/组装边界按扩展规约不变。

---

## 1. 缺口 → 扩展点 / 类 / 禁止项

| # | 缺口 | 机制 / 扩展点 | 拥有模块 · 落点类 | 改什么 | 禁止 |
|---|------|---------------|-------------------|--------|------|
| **G1** | XML Sitemap 未收录 `/policy/*`（含 accessibility） | `SitemapUrlProvider` | `Weline_Theme` · `extends/module/Weline_Seo/SitemapUrlProvider/StorefrontStaticSitemapUrlProvider.php` | 在 `ROUTES` **增补** `policy/...` 静态路由（见 §3） | ① 在 Seo 核心硬编码 Theme 法律 URL 列表（反向依赖）② 在 `layouts/policy/*.phtml` 写 SEO/sitemap ③ 业务模块直写 `weline_sitemap_url` |
| **G2** | Inspector：`page_type=policy` / 布局别名未→`legal`；URL 无 `/policy/` 推断 | Seo 店面 inspector（非店面渲染热路径） | `Weline_Seo` · `view/statics/seo-inspector/inspector.js` | ① `PAGE_JSONLD_RULE_ALIASES` 增补 `policy`/`accessibility`/`cookie`/`shipping`/`refund`/`disclaimer`/`term_condition`/`privacy`/`terms`…→`legal`（`privacy`/`terms`/`legal` 已有则核对齐全）② `inferSeoTypeFromUrlPath`：路径匹配 `/policy` 或 `/policy/` → 返回 `legal` | ① 要求 JSON-LD `@type=AccessibilityPage` ② 在 phtml 手写 JSON-LD 骗过 inspector |
| **G3** | `content-category` 只认归一后的 `legal`，不认 `policy` 等别名 | Head 组装（Seo 核心） | `Weline_Seo` · `Service/Head/HeadRenderer.php` · `defaultContentCategory()`（必要时抽私有 `legalPageTypeAliases()`，**勿**把 `headPageType` 输出改成 `legal`） | `normalize` 后若属法律/政策别名集合 → 默认 `content-category=legal`；`webPageType()` 继续走 `default → WebPage` | ① 把 Theme 发布的 `page_type` meta 强改成 `legal`（破坏「事实=policy」）② 发明 AccessibilityPage ③ 跨模块 Theme→Seo Service 直调 |

---

## 2. 类型边界（冻结意图）

```text
Theme（事实层）
  page_type = policy          ← assignThemeShellSeo(..., 'policy')
  breadcrumbs = Home → 当前页
  robots = index,follow（公开壳）

Seo（归一 / 组装层）
  content-category 默认：policy|privacy|accessibility|cookie|shipping|refund|disclaimer|term_condition|terms|legal… → "legal"
  inspector 规则键：同上别名 → PAGE_RICH_EXPECTATIONS.legal
  JSON-LD 壳 @type：WebPage（webPageType 不新增 AccessibilityPage / LegalPage）
  page-type meta：保持 Theme 事实（policy / 连字符 policy），由 inspector 别名归一
```

| 层 | 值 | 说明 |
|----|-----|------|
| Theme bag `seo.page_type` | `policy` | **发布事实**；布局 option（privacy/accessibility…）不改写为独立 page_type |
| `<meta name="page-type">` | `policy`（及既有连字符化） | 与 bag 一致；别名归一在 Seo 消费侧 |
| `<meta name="content-category">` | `legal` | G3 补齐默认映射 |
| JSON-LD 主壳 | `WebPage` | 与 `PAGE_JSONLD_RULES.legal.primaryType` / `PAGE_RICH_EXPECTATIONS.legal` 一致 |
| Inspector rich 期望 | `legal` | WebSite + Organization + BreadcrumbList（required） |

---

## 3. Sitemap（G1）落点裁定

### 3.1 采用：既有 `StorefrontStaticSitemapUrlProvider` 增补 ROUTES

**理由（框架正确落点）：**

1. 政策公开路由由 **Theme** 拥有（`Controller/Frontend/Policy`、`layouts/policy/*`、页脚/顶栏链接 Helper），符合「拥有模块实现 `SitemapUrlProvider`」规约。
2. 该类已声明「Theme-owned public marketing/static routes」；`/policy/*` 与 `about`/`products` 同属静态店面路由，非商品/CMS 实体。
3. 同步路径仍是 Seo `SitemapUrlSyncService` 发现 Provider → 写表；**不**引入 Theme→Seo Service 直调。
4. Sitemap 同步属 cron/后台批量，**非**店面热路径渲染（见 §5）。

**建议 ROUTES 增补（与 `Policy::layoutExists` 白名单对齐，排除无稳定公网语义的 `default`）：**

| path | priority | changefreq | metadata.page_type | url_key |
|------|----------|------------|--------------------|---------|
| `policy/privacy` | `0.5` | `yearly` | `policy` | `theme-static:policy/privacy` |
| `policy/cookie` | `0.5` | `yearly` | `policy` | `theme-static:policy/cookie` |
| `policy/term-condition` | `0.5` | `yearly` | `policy` | `theme-static:policy/term-condition` |
| `policy/refund` | `0.5` | `yearly` | `policy` | `theme-static:policy/refund` |
| `policy/disclaimer` | `0.5` | `yearly` | `policy` | `theme-static:policy/disclaimer` |
| `policy/shipping` | `0.5` | `yearly` | `policy` | `theme-static:policy/shipping` |
| `policy/accessibility` | `0.5` | `yearly` | `policy` | `theme-static:policy/accessibility` |

`getScope()` / `getModule()` 保持 `storefront_static` / `Weline_Theme`。

### 3.2 否决：新建独立 `PolicySitemapUrlProvider.php`

| 选项 | 结论 |
|------|------|
| **A（采用）** 扩 `StorefrontStaticSitemapUrlProvider::ROUTES` | **冻结推荐** — 同 owner、同 scope、同 WebsiteCatalog 注入，改动面最小 |
| B 新建 `ThemePolicySitemapUrlProvider` | **否决（本需求）** — 无独立生命周期/开关需求；会双 Provider 扫同一批静态法律 URL，增加同步与验收分叉 |
| C 在 `Weline_Seo` 核心 hardcode `/policy/*` | **禁止** — 反向依赖 Theme 路由表 |

若未来政策路由改为「可配置/多主题差异很大」，再 escalate 评估独立 Provider 或发现自 `HeaderPolicyLinksHelper::discoverPolicyOptions`；**本需求不引入**。

---

## 4. 跨模块禁令（硬）

1. **禁止** `layouts/policy/*.phtml`（含 `accessibility.phtml`）新增 meta / JSON-LD / canonical / robots / content-category。
2. **禁止** Theme 跨模块 `new` / 直调 `Weline\Seo\Service\*` / Model；仅 bag 事实 + `SitemapUrlProvider` 扩展目录。
3. **禁止** Seo 核心硬编码 Theme 法律页 path 列表（G1 必须落 Theme Provider）。
4. **禁止** 为无障碍页发明 schema.org `AccessibilityPage` 或改 `webPageType` 特判。
5. **禁止** 对齐冻结前私改已冻 UC 意图（本波 UC 尚未冻；冻后遵守）。

---

## 5. 性能（与性能检查工程师协作门）

| 判定项 | 结论 |
|--------|------|
| 店面热路径渲染 / HotCache 键 / FPC 键是否因本需求变更？ | **否**。政策页 FPC Extra 已含 `/policy`、`/policy/*`；本需求不改 FPC namespaces/patterns |
| Sitemap 同步 | cron / 后台「同步 Provider」批量路径，**非**店面请求热路径 |
| 列表 / N+1 | 无 |
| roster 性能席 | **默认不上**；架构师认定 **无需** escalate 拉 `Team:性能检查工程师:` |

若施工中误改 FPC Extra / HotCache key 生成，须停工 escalate PM 拉性能席。

---

## 6. 源码核对指针（施工对照，非本波改码）

| 文件 | 现状要点 |
|------|----------|
| `Theme/.../StorefrontStaticSitemapUrlProvider.php` | `ROUTES` 仅 about/products/categories/best-sellers/new-arrivals；**无 policy** |
| `Theme/.../Policy.php` | `assignThemeShellSeo(..., 'policy')`；白名单含 accessibility 等 |
| `Seo/.../HeadRenderer.php` | `defaultContentCategory` 仅 `'legal' => 'legal'`；`webPageType` 无 policy 特判→WebPage |
| `Seo/.../inspector.js` | `PAGE_JSONLD_RULE_ALIASES` 有 `legal`/`privacy`/`terms`；**缺** `policy`/`accessibility`/…；`inferSeoTypeFromUrlPath` **无** `/policy` |
| `PAGE_RICH_EXPECTATIONS.legal` | 已存在；别名到位即可复用 |

---

## 7. 机制冻结清单（对齐冻结会勾选）

- [x] G1 → Theme `StorefrontStaticSitemapUrlProvider` 增补（非新建 Provider、非 Seo 硬编码）
- [x] G2 → inspector 别名 + `/policy` URL 启发式 → `legal`
- [x] G3 → HeadRenderer content-category 别名 → `legal`；page_type 事实保持 `policy`；JSON-LD=`WebPage`
- [x] 禁止页内 SEO；禁止 AccessibilityPage
- [x] 不上性能席（除非 FPC/HotCache 键变更）

> 勾选于 2026-09-22 对齐冻结会（测试主持）；纪要见 `meetings/align-freeze.md`。

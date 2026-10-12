---
status: clarified
work_kind: feature
feature_slug: header-hamburger-category-lazy
module: Weline_Theme
updated: 2026-10-12
session_path: ../../../../../../../dev/session/header-hamburger-category-lazy.md
team_path: ../../../../../../../dev/team/header-hamburger-category-lazy/
fe_be_scope: frontend+theme+api/binquery
ecommerce_advisor_stance: pending
performance_engineer: required
---

# Header 汉堡「全部」分类懒加载

## 澄清记录

| # | 问题 | 答复 / 状态 |
|---|------|-------------|
| Q1 | 横向顶栏分类「掉了」是否本 feature 施工？ | **否**。已由 PM 热修（`extends:rebuild` + `framework:compile` + `server:reload`）恢复；本规格仅将其写入背景作回归边界。 |
| Q2 | 懒加载范围？ | **本需求确认**：汉堡「全部」抽屉内分类树。顶栏横向 mega SSR 是否一并懒加载 → **待用户裁决**（见开放问题）。 |
| Q3 | 抽屉分类数据源？ | 默认假设：与横向条同源的 **product catalog 店面树**（`女装/男装/…`）。侧栏今日优先 AllMenu 页面种子（约 6 项 CMS）≠ catalog — **需电商顾问 stance** 确认是否允许 AllMenu 自定义树覆盖；未表态前不得冻结「覆盖优先级」。 |
| Q4 | 电商顾问 | **pending**（触发电商导航 / Catalog；对齐冻结会前须 `stance` + `supported_countries`）。 |
| Q5 | 失败态 | 假设：接口失败展示可观察空态/重试提示，**禁止**静默回填整页 SSR 大树骗「已加载」。待顾问/原型确认文案。 |

### 开放问题（≤5 · 停工由 PM 汇总问用户）

1. 顶栏横向 **mega / category-menu** 是否也要懒加载（用户是否一并减体积）？
2. 抽屉分类：仅 catalog，还是 AllMenu 自定义树可覆盖 catalog？
3. 二次打开汉堡：允许会话内客户端缓存，还是每次强制请求？
4. 接口失败：空态 +「重试」按钮，还是仅文案？
5. `ProductCatalogQueryConsumer` 吞异常致「分类静默消失」是否另开可观测性 plan（本 feature **非目标**，仅登记）？

---

## 背景（已证实诊断 · 禁止重猜）

> 以下事实来自 SESSION / PM 诊断，本席只转录，不重新推断根因。

1. `generated/extends.php` 曾丢失几乎全部模块 QueryProvider → `catalog` 未注册。
2. `ProductCatalogQueryConsumer::tree` catch 后返回 `[]` → `HeaderCommerceData` 只剩「全部商品」。
3. 热修：`extends:rebuild` + `framework:compile` + `server:reload`；`navTree` 已有女装/男装等；店面横向 `category-link` 已回。
4. 侧栏 `#categories-sidebar-list` 仍优先 AllMenu 页面种子（约 6 项 CMS），**不是** catalog 树。
5. 首页 HTML ~**751KB**，其中 category-menu 区域约 **62KB**（含 mega）。

入口参考：`https://p05113ef3.test.weline.com:9555/`  
触发器：暗色主导航左簇「全部」汉堡（`#hamburger-menu` / `data-testid="all-menu"`）→ `#categories-sidebar`。

---

## 目标 / 非目标

### 目标

1. 访客点击顶栏「全部」打开抽屉后，**才**通过站内接口加载分类树并渲染到 `#categories-sidebar-list`。
2. 首屏 SSR **只渲染抽屉壳**（品牌头、首页/快捷/设置等壳段可保留；分类列表区无整树 HTML）。
3. 减小首屏 HTML 体积（相对基线：汉堡分类区不再内嵌整树；可度量）。
4. 回归：横向顶栏 catalog 分类在热修后状态保持可见（本 feature 不得再次弄丢）。

### 非目标

- 不在本 feature 内修复 `extends.php` / QueryProvider 编译耐久防丢（`hotfix-extends-query` 热修已 closed；耐久防再丢交架构/文档另项）。
- 不擅自改横向 mega 为懒加载（待用户裁决）。
- 不改 `ProductCatalogQueryConsumer` 吞异常策略为本 feature 交付（可观测性另议）。
- 不新建平行 REST；禁止业务层手写 fetch 旁路 BinQuery。
- 不把站/品牌种子写进 `app/code` 模块。
- 本席（需求分析）**禁止改业务码**。

---

## 角色

| 角色 | 诉求 |
|------|------|
| 店面访客 | 点「全部」能看到与货架一致的分类；首屏更轻、更快。 |
| 主题/运营编辑 | AllMenu 部件仍可配置「全部」按钮文案与「全部商品」开关；自定义树是否覆盖 catalog 听顾问裁决。 |
| 工程/运维 | QueryProvider 注册健全时分类可用；接口可 `query:help` 发现。 |

---

## 用户故事

1. 作为访客，我想点开「全部」后再加载分类树，以便首屏 HTML 更小、首屏更快。
2. 作为访客，我希望抽屉里的分类与顶栏横向 catalog 分类语义一致（女装/男装等），以免两套导航打架。
3. 作为访客，当接口失败时，我应看到明确空态/可重试，而不是空白或假数据。

---

## EARS（验收）

1. WHEN 任意店面页（含首页）首次 SSR 完成，系统 SHALL 在 `#categories-sidebar-list`（或等价懒加载容器）**不**输出完整 catalog/AllMenu 分类树 HTML（允许空壳、loading 占位、`data-*` 钩子）。
2. WHEN 访客点击「全部」打开 `#categories-sidebar`，系统 SHALL 发起 **BinQuery**（或框架一等站内 Query 入口）请求分类树，且该请求发生在打开手势之后（Network 可证）。
3. WHEN 接口成功返回，系统 SHALL 将分类节点渲染进侧栏列表，且至少包含与当前站 catalog 顶层一致的可点击项（热修后基线：女装/男装等）。
4. IF 接口失败或返回空树，THEN 系统 SHALL 展示可观察失败/空态（文案或重试），SHALL NOT 回退为把整树 SSR 进首屏 HTML。
5. WHILE 本 feature 交付后，顶栏横向 `category-menu` 在 QueryProvider 正常时 SHALL 仍展示 catalog 横向分类（回归，不因懒加载施工回退到仅「全部商品」）。
6. WHEN 对比改前/改后同 URL 禁缓存 HTML，汉堡分类区（侧栏列表）体积 SHALL 相对基线下降（证据：字节差或区域剥离后大小；目标方向：去掉约 62KB 量级中侧栏树贡献，具体数值由性能席钉阈值）。

---

## 框架映射（framework_first · 澄清层）

> 映射「用哪类框架机制」，不写补丁步骤 / 类级施工清单（how 归架构）。

| 关注点 | 框架抽象 / 现有落点 | 本需求约束 |
|--------|---------------------|------------|
| 汉堡触发器 | Theme `all-menu` 部件槽 `all-menu`；按钮 `data-testid="all-menu"` → `#categories-sidebar` | SSR 只保留触发器+抽屉壳；禁止在部件内为侧栏整树做首屏 HTML |
| 抽屉壳 | Header partial `#categories-sidebar` / `#categories-sidebar-list` | 列表改为懒加载容器；壳段（首页/快捷/设置）可 SSR |
| 横向分类（回归） | `HeaderCommerceData::resolveCategoryNavItems` → Product `StorefrontAllMenuCategoryTreeService::navTree` → `catalog` 空间 | 本 feature 不拆横向通路；施工不得破坏 |
| 读树契约 | QueryProvider **`catalog` / `tree`**（`CatalogQueryProvider`，descriptor 已 `frontend => true`） | 共用 Query 核；店面经 BinQuery/`Weline.Api`，禁止平行 REST |
| 消费者桥 | `ProductCatalogQueryConsumer::tree` → `w_query('catalog','tree')` | 服务端热路径可继续用；**首屏侧栏禁止再 SSR 整树** |
| AllMenu 发布 | `AllMenuTreeRegistry::publish` + `PageCandidateService::defaultSeedTree`（约 6 页种子） | 今日侧栏优先 published 种子 → 与 catalog 不一致；覆盖策略待顾问 |
| 缓存 | Header / Product category_menu HotCache 策略 | 懒加载后侧栏 HTML 不得再依赖首屏片段灌整树；缓存键与 locale/website 须合规 |
| 扩展点 | Query + Widget/部件 + Theme chrome；涉 API 面 → **Team:API:**；涉热路径体积 → **性能检查工程师** | 禁止跨模块直调 Model；禁止 Theme 内嵌外国业务树构建 |
| 前端 I/O | `Weline.Api` / BinQuery（参照 header-search 等） | Attribute 默认拒绝；`catalog.tree` 已 frontend opt-in，施工核验 auth/external 矩阵 |

---

## 用例（可执行 · 真机）

验收 Host 默认：`http://p05113ef3.test.weline.com:9555/`（Markdown 可点链与探活字面一致；Cursor Browser 禁缓存 + 抹 `navigator.webdriver`）。

### UC-horiz — 横向分类回归（主成功 · 热修后基线）

| 字段 | 内容 |
|------|------|
| id | `UC-horiz` |
| 角色 | 访客 |
| 前置 | QueryProvider 已 compile（`catalog` 已注册）；本机店面可访问 |
| 步骤 | 1. Browser 打开首页（禁缓存）。2. 截图并 Read 暗色导航条。3. 断言横向出现 catalog 顶层链（至少「女装」或等价 `category-link`），不只「全部商品」。 |
| 备选/异常 | 若 extends 再次丢失 → 本 UC fail；记运维/架构，不记本懒加载实现成功。 |
| 期望 | 横向分类可见；与 CLI `navTree` 非空一致。 |
| 映射 acceptance | `acc-uc-horiz-wb`（Browser WB-OP + 截图 Read） |
| 证据 | 截图路径；可见分类文案列表；HTTP 状态 |

### UC-hamburger-shell — 首屏无整树（主成功）

| 字段 | 内容 |
|------|------|
| id | `UC-hamburger-shell` |
| 角色 | 访客 / 测试 |
| 前置 | 同首页；未点击「全部」 |
| 步骤 | 1. `curl` 或 Browser 取 HTML。2. 定位 `#categories-sidebar-list`（或约定 `data-sidebar-category-tree` 容器）。3. 断言：无完整分类树节点集（无女装/男装等整树 li 批量内嵌；允许空/`data-lazy` 占位）。4. 可选：剥离 category-menu+sidebar 区域字节，对比改前基线。 |
| 备选 | 编辑器 preview_mode 可保留演示树 — 须标注非店面主路径。 |
| 期望 | SSR 壳成立；分类区无整树。 |
| 映射 acceptance | `acc-uc-shell-html`（HTML 断言 + 体积抽样） |
| 证据 | HTML 片段哈希/行数；区域字节；与基线 751KB / 62KB 对照说明 |

### UC-hamburger-lazy — 点开后接口加载（主成功）

| 字段 | 内容 |
|------|------|
| id | `UC-hamburger-lazy` |
| 角色 | 访客 |
| 前置 | UC-hamburger-shell 成立 |
| 步骤 | 1. Browser 打开首页（禁缓存、抹自动化）。2. Network 监听 `/bin/query` 或框架 Query 入口。3. 点击「全部」（`data-testid="all-menu"`）。4. 断言抽屉 `aria-hidden=false` / 可见。5. 断言打开后才出现分类树请求。6. 等待列表出现 catalog 顶层项。7. 截图 Read 侧栏。 |
| 备选 | 若顾问裁定 AllMenu 覆盖：断言项来自发布树且仍为点击后加载。 |
| 期望 | 点后加载；列表为真实分类（非 6 项 CMS 壳页冒充货架，除非顾问明确允许）。 |
| 映射 acceptance | `acc-uc-lazy-wb`（WB-OP + Network + 截图） |
| 证据 | 请求 URL/时序；响应顶层 name 列表；截图路径 |

### UC-hamburger-fail — 接口失败空态（异常）

| 字段 | 内容 |
|------|------|
| id | `UC-hamburger-fail` |
| 角色 | 访客 |
| 前置 | 可模拟 Query 失败（测试席定：断网 / 桩仅测试环境 / 错误参数）；**禁止**用假 JSON 宣称业务 PASS |
| 步骤 | 1. 打开首页。2. 制造失败条件。3. 点「全部」。4. 断言可见空态/错误/重试；首屏 HTML 仍无整树回填。 |
| 期望 | 可观察失败；无静默假树。 |
| 映射 acceptance | `acc-uc-fail-wb` |
| 证据 | 截图；无整树 HTML 抽检 |

### UC-size — 体积收益抽检（性能证据）

| 字段 | 内容 |
|------|------|
| id | `UC-size` |
| 角色 | 性能检查 / 测试 |
| 前置 | 同 URL 改前基线已存（751KB / category-menu≈62KB） |
| 步骤 | 1. 禁缓存拉取首页 HTML。2. 记录 `Content-Length` 或字节。3. 剥离侧栏分类列表相关片段比大小。4. 写入性能纪要。 |
| 期望 | 侧栏树 SSR 贡献下降；方向与用户减体积意图一致（精确阈值由性能席与架构共钉）。 |
| 映射 acceptance | `acc-uc-size-probe` |
| 证据 | 改前/改后字节表；命令与输出 |

---

## 电商顾问约束（pending）

- **触发**：Catalog / 店面导航 / 汉堡分类 IA。
- **stance**：`pending` — 对齐冻结会前必须补齐；未表态不得冻结「抽屉数据源优先级」与「是否允许 AllMenu 覆盖」。
- **supported_countries**：pending（顾问补）。
- **本规格默认假设**（可被顾问否决）：抽屉展示与横向同源的 product catalog 树；CMS 页种子不得再优先占满 `#categories-sidebar-list`。

---

## 架构隐患 / 周边发现（扫码顺带 · 扩 scope 须用户同意）

| 级别 | 发现 | 建议 |
|------|------|------|
| 架构级 | `ProductCatalogQueryConsumer::tree` 吞异常 → 分类静默消失 | 另开可观测性/硬失败 plan；**本 feature 非目标** |
| 架构级 | `generated/extends.php` 可再次丢 QueryProvider | 耐久防丢交架构/Setup/文档；热修已做 |
| 补丁级→产品 | 侧栏优先 AllMenu 页种子 vs catalog | 顾问 stance + 本懒加载一并裁定 |
| 产品/性能 | 横向 mega 仍 SSR ~62KB 区域 | 开放问题 1；用户裁决是否顺带 |

---

## 验收证据清单（收口必写）

- [ ] 全部 `UC-*` 被计划 `acceptance[].covers_use_cases` 覆盖且真机 `passed`
- [ ] WB-OP：禁缓存、抹自动化、截图 **Read**（UC-horiz / UC-hamburger-lazy）
- [ ] Network：打开汉堡后才有分类 Query
- [ ] HTML：首屏 `#categories-sidebar-list` 无整树
- [ ] 体积：改前/改后字节（UC-size）
- [ ] 电商顾问 `stance` 非 pending（冻结前）
- [ ] 性能检查工程师对阈值/缓存表态
- [ ] 禁止仅用 PHPUnit 契约冒充本需求闭环

---

## 就绪检查

- [x] `status` = `clarified`（电商 stance 与开放问题 1–2 关闭后可升 `ready-for-plan`）
- [x] ≥1 用户故事 + ≥2 EARS
- [x] ≥1 主成功 UC + 异常 UC + 回归 UC + 体积 UC
- [x] 每条 UC 已填映射 acceptance id
- [x] 非目标明确
- [x] 框架映射表
- [x] 真机 UC（Browser / HTML / Network）
- [ ] 电商顾问 stance（pending → 阻塞冻结，不阻塞本澄清落盘）
- [ ] 开放问题 1–2 用户/顾问裁决

## 建议下一波席位

`电商顾问` ∥ `领域探查` → `架构师` + `性能检查工程师` + `扩展点` + `查询` → 对齐冻结（`测试` 主持）→ 施工：`API` + `前端` + `主题开发工程师` + `部件开发工程师` + `原型` + `UI`（有附图=视觉 UI）→ 复审/测试/文档工程师。

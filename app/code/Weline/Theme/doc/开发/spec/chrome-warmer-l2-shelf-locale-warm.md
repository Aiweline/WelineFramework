---
status: ready-for-plan
work_kind: feature
feature_slug: chrome-warmer-l2-shelf-locale-warm
module: Weline_Theme
updated: 2026-10-10
session_path: ../../../../../../../dev/session/chrome-warmer-l2-shelf-locale-warm.md
team_path: ../../../../../../../dev/team/chrome-warmer-l2-shelf-locale-warm/
related_specs:
  - storefront-chrome-locale-hotcache-prime.md
  - ../../Product/doc/开发/spec/search-projection-scope-hotcache-prime.md
covers_use_cases:
  - UC-chrome-L2-HIT
  - UC-chrome-head-warm
  - UC-shelf-offers-warm
  - UC-bn_BD-ns-gone
  - UC-no-shell-strip
mcp_note: prepare_project/aoci_rules timed out this seat turn; continued via AI硬规则索引.md + 需求澄清与用例规格.md + findings/contracts on disk.
---

# Spec: chrome warmer L2 HIT + homepage shelf/offers 语种维预热 + env locale 去污染

## 1. 背景（证据锚定）

Host：`https://p05113ef3.test.weline.com`  
证据：

- `dev/tmp/perf-cold-chaos-p05113ef3-20261010-postchrome-findings.md`（fr_FR home）
- `…-postchrome-r2-findings.md`（it_IT home）
- `…-postchrome-r3-findings.md`（es_ES home）

既有 guest-neutral chrome（`storefront-chrome-locale-hotcache-prime`）已落地：Clear 汇报 chrome locales 已预热 23–25 条，但 soft-clear 后**安静窗 cold hit1** 仍见：

| 残税族 | 典型 exclusive（quiet cold hit1） | 样本 |
|--------|-----------------------------------|------|
| `theme.storefront_chrome` + `theme.storefront_head` L1/L2 absent | chrome ~0.31–0.43s；head ~0.09–0.25s | fr_FR / it_IT / es_ES home |
| `product.homepage_shelf_id_plan` + `catalog_offers_summary(_lang)` 空袋 | 合计 ~0.20–0.35s | fr_FR / es_ES |
| chrome namespace 夹 `global/i18n/bn_BD` | 本机 env locale=bn_BD 污染 | 与请求语种并存 |

粘性 hit2 ~0.75–0.80s **只证明粘，不证明冷修成功**。矩阵 tip 可含叠建尖峰（如 es_ES 4.5s），**主证据用安静窗 cold hit1**。

前序规格：`storefront-chrome-locale-hotcache-prime.md`（guest-neutral 键 + warmer 计数）——本规格升级验收为 **L2 HIT 证明法**，并纳入 Product 货架袋与 ops env locale。

## 2. 目标 / 非目标

### 目标

1. soft-clear + chrome warmer 完成后，启用语种 **home** 安静窗 cold hit1：**不再**以 `theme.storefront_chrome` / `theme.storefront_head` 空袋 builder 为主税（见 UC 阈值）。
2. 同窗 homepage **shelf plan + offers summary(_lang)** 语种维已预热或首触 exclusive 显著低于基线。
3. 本机 env 默认 locale 与站默认语一致后，非 bn_BD 请求的 chrome/i18n namespace **不再夹** `global/i18n/bn_BD`。
4. 全程 **禁拆壳**（header/footer/必装 widget 保持完整）。

### 非目标（硬）

- **禁止**把拆 header / footer / 必装 widget / featured 货架部件当作优化或验收手段（`theme_seat_integrity_over_peer_requests`）。
- **禁止**把 warmer「已预热 N 条」单独当作 L2 HIT 证明。
- **禁止**把粘性 hit2 墙钟当作冷修主证据（可作辅证「粘成立」）。
- **禁止**把矩阵 tip 叠建尖峰单独说成稳态冷税。
- **禁止**为消 bn_BD 改 chrome vary 架构或硬编码语种白名单进 Runtime。
- **禁止**把 chrome 与 Product catalog bags 混成一袋。
- **禁止**在本规格阶段改业务 PHP/CSS/模板（澄清席只冻规格）。

## 3. 澄清记录

| # | 问题 | 结论（本席冻结） | 来源 |
|---|------|------------------|------|
| C1 | 主验收面？ | 默认站 **home** + 启用非基线语种（至少 fr_FR / it_IT / es_ES 各一条安静窗） | findings 同族 tip |
| C2 | 主证据窗？ | soft-clear 后 **安静窗 cold hit1**（`wls_tpl_perf=1`）；hit2 仅辅证粘性 | postchrome / r2 / r3 |
| C3 | warmer 报数是否算过？ | **不算**；须 L2 HIT 或 builder absent / exclusive≪基线 | escalate 原文 |
| C4 | `storefront_head` 是否进本需求？ | **是**（与 chrome 同族空袋；P-theme-warmer-l2） | r2/r3 |
| C5 | Product 范围？ | `homepage_shelf_id_plan` + `catalog_offers_summary` + `_lang`；不扩 publishedOffers(1000) | findings + Seeder 既有边界 |
| C6 | bn_BD？ | ops：校正本机 env 默认 locale；禁改 chrome Policy 仅为消 ns | findings mechanism #3 |
| C7 | 与前序 guest-neutral？ | 保留；本需求解决 **暖键↔请求键对齐 / durable L2 可证 / shelf 语种维 / env** | 前序 spec |
| C8 | 成功墙钟绝对值？ | **不设唯一墙钟 KPI**；以独占 builder 族阈值为主（见 EARS）。整体 quiet cold 相对基线改善可作为辅证 | 避免叠建尖峰误杀 |

### 可选用户拍板（≤5，不阻塞 `ready-for-plan`；默认已选）

| # | 项 | 默认（已冻结进 UC） | 若否需用户改 |
|---|-----|---------------------|--------------|
| Q1 | chrome/head exclusive 通过线 | cold hit1：**无**对应大 builder，或各自 exclusive **&lt; 基线 40%**（基线取同 URL 修前 quiet cold，约 chrome 0.3–0.4s / head 0.1–0.25s） | 更严「必须零 builder」 |
| Q2 | shelf+offers 通过线 | 三袋 exclusive **合计 &lt; 0.12s** 或均无大 builder | 改绝对阈值 |
| Q3 | 必测语种集合 | 至少 **fr_FR + it_IT + es_ES** home USD（与 findings 对齐） | 扩全启用语 |
| Q4 | bn_BD 站启用语是否保留 | **保留站启用语 bn_BD**；只去 **env 默认 locale 污染**（非 bn_BD 请求 ns 不夹 bn_BD） | 若要从站启用列表移除 bn_BD 另开需求 |
| Q5 | Clear 预热超时 | 沿用既有 chrome warmer 诚实 remaining；shelf 预热不得拖垮 Clear 预算到不可用 | 调 BUDGET |

## 4. 架构级映射（mechanism · owning · not_to_do）

| plan_id | mechanism（what） | owning_module | not_to_do |
|---------|-------------------|---------------|-----------|
| P-theme-warmer-l2 | soft-clear / `warmUp(weline_theme_storefront_chrome)` 后，请求路径与 warmer 写入的 **logical key / durable L2** 对齐，使 quiet cold home 对启用语 **HIT** `theme.storefront_chrome`；并覆盖同族 **`theme.storefront_head`** 预热或键对齐（页作用域袋须有可证 HIT 路径，而非仅 Clear 计数） | `Weline_Theme`（Warmer / Partials / chrome coordinator）；Clear 编排属 `Weline_Framework`（无 Theme FQCN soft-pull） | 禁拆壳；禁空烧假 HIT；禁把「warmed=N」当验收；禁 Runtime 硬编码语种列表 |
| P-product-shelf-warm | soft-clear 后对启用语种维预热（或保证 locale_bag_prime / 等价 warmer 路径）`product.homepage_shelf_id_plan` 与 `catalog_offers_summary` / `_lang`，使 home 首触不再付空袋冷建主税 | `Weline_Product`（`StorefrontHotCacheBagSeeder` / catalog coordinator / 既有 CacheWarmer provides） | 勿与 chrome 混袋；禁 full×locales / publishedOffers(1000) 塞进 light；禁拆 featured widgets |
| P-ops-env-locale | 本机 `env` 默认 locale 与网站默认语一致；非 bn_BD 请求的 chrome/i18n namespace 证据中不再出现夹带的 `global/i18n/bn_BD` | 运维/本机配置；Framework `LocaleFallbackChain::websiteDefaultLocale` 仅作只读对齐参考 | 禁为消 bn_BD 改 chrome vary 架构；禁把站启用语 bn_BD 误删当「修复」 |

framework_first 触点：HotCache Policy / CacheWarmerRegistry / Clear `warmStorefrontFpcAfterClear` / Theme chrome bags / Product catalog bags——**不**发明平行进程内袋。

## 5. 用户故事

### US-1 Theme chrome / head L2

As a **店面访客（新语种首触）**,  
I want **soft-clear 预热后首页 chrome/head 已在 durable 缓存**,  
so that **首屏不再为 header/chrome 空袋支付 ~0.3–0.6s 独占 builder**.

### US-2 Product shelf / offers

As a **店面访客（首页货架）**,  
I want **语种维 homepage shelf plan 与 offers summary 已预热**,  
so that **冷切首页不再为货架/报价摘要空袋额外支付 ~0.2–0.35s**.

### US-3 Ops env locale

As a **本机性能验收操作者**,  
I want **env 默认 locale 不污染非该语请求的 i18n/chrome namespace**,  
so that **冷切诊断不被 bn_BD 夹带干扰，且避免错误语种袋参与暖/读**.

## 6. EARS（验收标准）

### Theme（P-theme-warmer-l2）

- **E-T1** WHEN soft-clear（含 chrome warmer）完成后对启用语种 `/{locale}/?currency=USD` 执行安静窗 cold hit1（`wls_tpl_perf=1`）THEN 系统 SHALL **不**再以 `theme.storefront_chrome` L1/L2 absent 的大 exclusive builder 为主税（满足 Q1 默认阈值）。
- **E-T2** WHEN 同上 quiet cold hit1 THEN 系统 SHALL 对 `theme.storefront_head` 满足同等 HIT/阈值（Q1）。
- **E-T3** WHEN Clear/warmer 汇报「已预热 N 条」THEN 验收 SHALL **额外**要求请求侧 HIT 证据（tpl_perf 无大 builder 或 cache tier HIT）；单独 N **不得**判 PASS。
- **E-T4** WHILE 本需求验收进行中 THEN 系统 SHALL 保持完整 storefront chrome 壳（header/footer/必装 widget 仍在 DOM / 布局中）。

### Product（P-product-shelf-warm）

- **E-P1** WHEN soft-clear 后对同上 home URL quiet cold hit1 THEN `product.homepage_shelf_id_plan` 与 `catalog_offers_summary` / `catalog_offers_summary_lang` 的 exclusive **合计**满足 Q2，或均无大空袋 builder。
- **E-P2** IF 预热走 locale 维 THEN 系统 SHALL 覆盖至少 Q3 所列语种（与站启用语交集），且不得用 full×locales×publishedOffers(1000) 作为 light 预热手段。

### Ops（P-ops-env-locale）

- **E-O1** WHEN 本机 env 默认 locale 已与网站默认语对齐后，请求 `fr_FR`（或 it_IT/es_ES）home quiet cold THEN chrome/i18n namespace 证据 SHALL **不**夹带 `global/i18n/bn_BD`（除非该请求 locale 本身为 bn_BD）。
- **E-O2** IF 站启用列表仍含 bn_BD THEN 系统 SHALL 仍允许 `/{bn_BD}/` 正常访问；本项只消除 **env 默认污染**。

### 横切

- **E-X1** WHEN 报告冷修结果 THEN 证据 SHALL 以安静窗 cold hit1 为主；hit2 仅可标注「粘性成立」，不得单独宣称冷修 PASS。
- **E-X2** WHEN 矩阵 tip wall ≫ quiet cold THEN 报告 SHALL 标明叠建尖峰，不得把 tip 墙钟当作唯一 KPI。

## 7. 用例（UC）

### UC-chrome-L2-HIT

| 字段 | 内容 |
|------|------|
| id | UC-chrome-L2-HIT |
| 名称 | soft-clear 后 home chrome L2 HIT |
| 角色 | 性能验收 / 匿名访客 |
| 前置 | WLS 可用；默认站启用含 fr_FR；Host `https://p05113ef3.test.weline.com`；已 soft-clear 且 chrome warmer 跑完 |
| 主成功步骤 | 1) soft-clear 并记录 warmer message（locales 计数，仅辅证） 2) 安静请求 `GET /fr_FR/?currency=USD` + `_wb_nc` + `wls_tpl_perf=1`（cold hit1） 3) 解析 tpl_perf：检查 `theme.storefront_chrome` 4) 同 URL cold hit2 仅记录粘性 |
| 备选/异常 | warmer warmed=0 / skipped → FAIL 并记 message；禁止用拆壳降税重测 |
| 期望结果 | 满足 E-T1 + E-T3；相对基线 chrome exclusive ~0.38s 显著下降或 builder absent |
| 映射 acceptance | `acc-chrome-L2-HIT`（type：runtime tpl_perf / 正式冷切探针；计划 `covers_use_cases` 必含本 id） |
| plan_id | P-theme-warmer-l2 |

### UC-chrome-head-warm

| 字段 | 内容 |
|------|------|
| id | UC-chrome-head-warm |
| 名称 | storefront_head 与 chrome 同窗不再空袋主税 |
| 角色 | 性能验收 |
| 前置 | 同 UC-chrome-L2-HIT；样本至少 it_IT 或 es_ES（r2/r3 基线 head 明显） |
| 主成功步骤 | 1) soft-clear 2) quiet cold hit1 `/{locale}/?currency=USD` + tpl_perf 3) 断言 `theme.storefront_head` 满足 E-T2 |
| 备选/异常 | head 仍为页作用域且设计上不可预热 → 须在计划中给出 **可证替代**（键对齐说明 + 实测阈值），不得静默 skip |
| 期望结果 | E-T2 |
| 映射 acceptance | `acc-chrome-head-warm` |
| plan_id | P-theme-warmer-l2 |

### UC-shelf-offers-warm

| 字段 | 内容 |
|------|------|
| id | UC-shelf-offers-warm |
| 名称 | homepage shelf + offers 语种维预热 |
| 角色 | 性能验收 |
| 前置 | soft-clear；样本 fr_FR 或 es_ES home（findings 有 shelf/offers 数字） |
| 主成功步骤 | 1) soft-clear（含 Product 相关 warm 路径） 2) quiet cold hit1 + tpl_perf 3) 读取 `product.homepage_shelf_id_plan`、`product.catalog_offers_summary`、`product.catalog_offers_summary_lang` exclusive 4) 对照 Q2 |
| 备选/异常 | 仅 peek 无 seed → 首触仍空袋则 FAIL；禁止拆 featured 部件 |
| 期望结果 | E-P1、E-P2 |
| 映射 acceptance | `acc-shelf-offers-warm` |
| plan_id | P-product-shelf-warm |

### UC-bn_BD-ns-gone

| 字段 | 内容 |
|------|------|
| id | UC-bn_BD-ns-gone |
| 名称 | env 去污染后 ns 不再夹 bn_BD |
| 角色 | 运维 / 性能验收 |
| 前置 | 已将本机 env 默认 locale 对齐网站默认语（非 bn_BD）；soft-clear 一次 |
| 主成功步骤 | 1) 确认 env 默认 locale ≠ bn_BD 且 = 站默认 2) quiet cold `GET /fr_FR/?currency=USD`（或 it_IT/es_ES） 3) 检查 chrome/i18n namespace 列表 / dig 证据 4) 断言无夹带 `global/i18n/bn_BD` |
| 备选/异常 | 请求本身为 `/bn_BD/` 时允许出现 bn_BD ns；站启用仍可保留 bn_BD |
| 期望结果 | E-O1、E-O2 |
| 映射 acceptance | `acc-bn_BD-ns-gone` |
| plan_id | P-ops-env-locale |

### UC-no-shell-strip

| 字段 | 内容 |
|------|------|
| id | UC-no-shell-strip |
| 名称 | 禁拆壳门禁 |
| 角色 | 主题 / 测试 |
| 前置 | 任一冷修方案落地后的 home 页 |
| 主成功步骤 | 1) Browser 或 SSR 检查 header/footer/必装 chrome 仍在 2) 对照布局/required 注入未因性能删槽 |
| 备选/异常 | 任何「去掉 header 降税」提案 → 立即 escalate，本 UC FAIL |
| 期望结果 | E-T4 |
| 映射 acceptance | `acc-no-shell-strip`（可与主题契约/前端检查合并） |
| plan_id | main / P-theme-warmer-l2 |

## 8. 隐形需求摘要

- 沿用 Framework CacheWarmer / Clear warmUp 扩展点，禁止 Theme/Product FQCN soft-pull 回潮。
- guest-neutral header + 搜索岛 hydrate 契约保持（前序 spec）。
- 冷切测法：`_wb_nc`、安静窗、禁把 inclusive Σ 当单税（见 performance-cold-chaos-dig）。
- issuer：性能检查工程师；关项须 `issuer_acceptance=pass`。
- 本机验收 Host：`*.test.weline.com`（本 SESSION Host）。

## 9. 验收意图（计划期填写 covers）

| acceptance id | 建议 type | covers_use_cases |
|---------------|-----------|------------------|
| acc-chrome-L2-HIT | runtime / 冷切探针（正式 runner 或同等 tpl_perf 证据链） | UC-chrome-L2-HIT |
| acc-chrome-head-warm | runtime | UC-chrome-head-warm |
| acc-shelf-offers-warm | runtime | UC-shelf-offers-warm |
| acc-bn_BD-ns-gone | runtime / 配置核验 | UC-bn_BD-ns-gone |
| acc-no-shell-strip | Browser 或契约 | UC-no-shell-strip |
| e2e-plan-suite（若计划要求章套件） | e2e | 至少覆盖 home 多语抽样 + 禁拆壳；性能数字以 runtime 为准 |

fe_be_scope：frontend 可见（home chrome/shelf），改动以 Theme/Product/Framework 缓存预热与本机 env 为主；非视觉改版 → 审图非主路径，但禁拆壳须页面级确认。

## 10. 就绪检查

- [x] `status` = `ready-for-plan`
- [x] ≥1 用户故事 + 每故事 ≥2 条 EARS（US 横切 EARS 已覆盖）
- [x] ≥1 用例主成功路径（5 条 UC）
- [x] 每条 UC 已映射 acceptance id
- [x] 非目标明确（禁拆壳、禁粘性主证、禁 warmer 计数冒充 HIT）
- [x] 已点名 acceptance / e2e 意图；计划期须 `covers_use_cases` 关全 UC
- [x] 规格聚焦 what；how 留给架构计划（仅 framework_first 映射表）
- [x] 收口前须全 UC 闭环 + issuer_acceptance

## 11. 交叉引用

- Theme 前序：`doc/开发/spec/storefront-chrome-locale-hotcache-prime.md`
- Product 相关：`Weline_Product` → `StorefrontHotCacheBagSeeder` / `search-projection-scope-hotcache-prime.md`；本文件 **§4 P-product-shelf-warm / UC-shelf-offers-warm** 为货架袋权威 UC（可在 Product spec 链回本 slug）
- SESSION：`dev/session/chrome-warmer-l2-shelf-locale-warm.md`
- 纪要：`dev/team/chrome-warmer-l2-shelf-locale-warm/meetings/需求分析-clarify.md`

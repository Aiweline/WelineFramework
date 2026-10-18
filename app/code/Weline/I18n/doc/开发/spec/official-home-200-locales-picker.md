---
status: clarified
work_kind: feature
feature_slug: official-home-200-locales-picker
module: Weline_I18n
owning_module_candidates:
  - Weline_I18n          # 主归属：BCP47 映射、建议弹窗机制、locale 目录/启用协同、首页 chrome 词条
  - Weline_Websites      # WebsiteLanguage 成员集 / sort_order（≥200 启用）
  - Weline_Theme         # 店面 chrome 挂载点（若需 layout/hook；禁站品牌种子进模块）
updated: 2026-10-18
session_path: dev/session/official-home-200-locales-picker.md
team_path: dev/team/official-home-200-locales-picker/
fe_be_scope: frontend+backend-config
i18n: in_scope
ui: in_scope
---

# Spec: official-home-200-locales-picker

## 1. 背景 / 目标

用户要在**官网前端**：

1. 根据浏览器语言，用**弹窗**提示访客选择（切换到）匹配的系统语言；浏览器 BCP47 与 Weline locale 做映射。
2. 官网首页支持至少 **200** 个语言，并以 **CSV** 方式支撑全语种（见 §4「200 语言」分层定义，避免误解为「官方模块 Git 塞 200 个 locale CSV」）。

本规格只钉 **what / 验收 / 非目标**；类名级 how 留给架构与扩展点席。

## 2. 「官网」范围（钉死 · 默认 + 待拍板）

| 项 | 默认钉死 | 说明 |
|----|----------|------|
| 产品对象 | **官方品牌站**（站柜 `websites/aiweline.com/`，公网 `www.aiweline.com` / `aiweline.com`） | 非长安汉服 / 非 DaoCharms / 非 grocery |
| 配置主键 | **目标站的 `website_id`**；默认假设为 `Website::ID_DEFAULT`（`website_id=0`） | 与 SESSION 默认假设对齐 |
| 本机验收 Host | `{project_hash}.test.weline.com`（带 FPC 穿章） | 禁止把 `*.weline.test` 当主链 |
| 语言成员集 | `WebsiteLanguage::getWebsiteLanguageCodes(target_website_id)` ∩ 已安装且激活 Locale | Switcher 已有同一边界（见 I18n README） |

**待用户拍板 Q1（严重）**：本机/本仓当前 `website_id=0` 运营上常被当作长安汉服默认站；与「官网 = aiweline.com」可能不一致。请二选一：

- **A（推荐默认）**：本需求绑定 **系统默认站 `ID_DEFAULT=0`**；本机验收即默认站首页；上线时确保官方站生产库的 `ID_DEFAULT`（或官方站实际 `website_id`）启用 ≥200 语种。
- **B**：本需求绑定 **具名官网**（按 `website.code` / 站柜域名解析出的非 0 `website_id`），即使本机默认站是汉服也不改汉服语种集。

未拍板前，下游按 **A** 设计；若选 B，架构须把「目标站解析」写成显式契约，禁止硬编码汉服/品牌 slug。

**非范围站**：`changanhanfu.com`、`daocharms.com`、grocery 等兄弟站柜——除非用户另开需求。

## 3. 角色与用户故事

**角色**：官网匿名/登录访客；运维（启用语种 / 导入清单）。

**US-1**  
As a 官网访客, I want 系统用弹窗建议切换到与我浏览器匹配的语言, so that 我无需自己在语言菜单里翻找。

**US-2**  
As a 官网访客, I want 首页在至少 200 个已启用语言下看到目标语文案（非整页回落中文/英文）, so that 多语访客可用母语浏览首页。

**US-3**  
As a 运维, I want 一份可导入/可核对的 ≥200 locale CSV 清单与首页词条中英 CSV + 词典路径, so that 全语种可批量启用与译修。

## 4. 「200 语言」定义（三层 · 必须同时满足交付口径）

禁止把三层混为一谈。**交付验收以 L1+L2 为准**；L3 只证明库存够用。

| 层 | 名称 | 定义 | 本需求门槛 |
|----|------|------|------------|
| **L1** | 站启用语种 | 目标站 `WebsiteLanguage` 成员（∩ 已安装激活） | **≥ 200** 个 distinct Weline locale code |
| **L2** | 首页文案覆盖 | 首页可见 chrome / 布局词条在 L1 每个 locale 下有真实译文（展示 ≠ 中文 source；允许合理回落链但抽检须见目标语） | 覆盖**首页词条集**（见下）；官方模块 **仅** `zh_Hans_CN.csv` + `en_US.csv`；其余 locale → **系统词典**（硬规则 `模块翻译CSV规范` / Git 瘦身） |
| **L3** | CLDR/目录库存 | `Weline_I18n/data/locale-catalog`（MANIFEST：locales≈682，languages≈642） | **不作为**「已支持 200」的完成证据；仅保证可选池 ≥200 |

### 「做成 CSV」钉死含义

1. **启用清单 CSV（运维面）**：一份 ≥200 行的 locale 清单（列至少含 `locale_code`，建议含 `bcp47`、`native_name`、`enabled`），用于导入/核对目标站 `WebsiteLanguage`——**不是**把 200 个文件丢进每个官方模块 `i18n/`。
2. **首页词条 CSV（开发面）**：归属模块（主推 `Weline_I18n` 与/或承载首页 chrome 的 Theme/部件模块）维护 **中英** `i18n/*.csv`；非中英语种走词典 publish。
3. **禁止**：在官方框架模块 `i18n/` 下新增 `ja_JP.csv`…等 200 个 locale CSV 撑爆 Git。

首页词条集（最小）：语言建议弹窗标题/正文/「切换」「保持当前」「不再提示」；顶栏语言切换器相关 chrome；首页 layout 内用户可见静态短语（以冻结时 inventory 为准，架构/翻译席补全键表）。

## 5. 弹窗触发条件（钉死）

合并「首次 / 无 cookie」为**同一持久化偏好门闩**（名称实现期由架构定，规格称 `lang_pref_resolved`）。

**WHEN** 同时满足，系统 **SHALL** 打开建议弹窗：

1. 当前请求为**目标官网店面**（frontend area + 目标 `website_id`）；
2. **无**已持久化的 `lang_pref_resolved`（cookie 和/或 localStorage；二者任一有效即视为已决策——具体存储选型属 how，但验收须「关闭后再进首页不再弹」）；
3. 自浏览器得到 BCP47 候选（优先 `navigator.languages` 序，其次 `navigator.language`；服务端可用 `Accept-Language` 作 SSR/辅助，但店面弹窗以浏览器 API 为主）；
4. 映射得到 `suggested_locale` ∈ 目标站 L1 启用集；
5. `suggested_locale` **≠** 当前 `State::getLangLocal()`（或 URL/cookie 已解析的当前语）。

**IF** 映射失败、或不在 L1、或与当前语相同 → **SHALL NOT** 弹窗。  
**IF** 用户点「切换」→ 走与 `<w:i18n:switcher />` 同等的语言切换导航，并写入 `lang_pref_resolved`。  
**IF** 用户点「保持当前」或关闭 → 写入 `lang_pref_resolved`（保持当前语），**SHALL NOT** 在同浏览器再次自动弹（除非用户清除偏好）。  
**IF** URL 已显式带目标 locale 段且与建议相同 → 不弹（算已对齐）。

**非触发**：后台 area；非目标站；爬虫/无 JS（降级：无弹窗，不挡浏览）。

## 6. 弹窗 UI（钉死）

- **必须** `Weline.UI.dialog`（`data-w-component="dialog"` / `w-dialog`）。
- **禁止** `window.alert` / `window.confirm` / `window.prompt` / 私造 modal / 非 Weline.UI 浮层。
- 内容最小集：建议语言本地名 + locale code；主按钮「切换到 {语言}」；次按钮「保持当前」；可选「不再提示」（可与保持合并）。
- 可复用/对齐现有 `LanguageSwitcher` 内 `data-w-component="dialog"` 模式；**不得**用「申请支持语言」请求弹窗冒充本建议弹窗。
- 无障碍：`aria-labelledby`、可键盘关闭（与 Weline.UI dialog 契约一致）。

## 7. BCP47 → Weline locale 映射（钉死规则与落点）

**落点归属**：`Weline_I18n`（映射表/服务 + 可选 Query 只读暴露建议结果）；店面 JS 只消费契约，不私造平行映射表散落 Theme。

**输入**：BCP47 tag（如 `zh-CN`、`zh-Hans-CN`、`en`、`pt-BR`）。  
**输出**：Weline locale（如 `zh_Hans_CN`、`en_US`）或空。

**解析顺序（硬）**：

1. 规范化：`-`/`_` 统一、大小写按 IETF；去掉扩展/私有子标签（实现细节可架构细化，但不得改变下列优先级语义）。
2. **精确命中** L1 成员（例：`zh-Hans-CN` → `zh_Hans_CN`）。
3. **语言+地区** 别名表命中（例：`zh-CN` → `zh_Hans_CN`；`zh-TW` → `zh_Hant_TW` 若在 L1）。
4. **仅语言** 回落：在 L1 中选该语言的**站配置默认/展示序最高**成员（例：`en` → 站内首选 `en_US`）。
5. 仍无 → 空（不弹窗）。

**映射表形态**：可维护的别名数据（CSV 或模块配置——how 归架构）；须覆盖常见浏览器 tag（至少 zh/en/ja/ko/es/fr/de/pt/ru/ar 及 Hans/Hant）。  
**禁止**：在 `WlsRuntime`/编排层硬编码业务 slug；禁止 Theme 内写死 200 行私表而不走 I18n。

现有 `DetectLanguage` Observer **仅**校验「已安装激活」，**不**承担浏览器建议；本需求是增量能力，不扭曲其现语义。

## 8. EARS 验收标准

**US-1**

1. WHEN 访客首次打开官网首页且浏览器主语言映射到 L1 内且 ≠ 当前语，THEN 系统 SHALL 以 `Weline.UI.dialog` 展示建议切换。
2. IF 访客已有 `lang_pref_resolved`，THEN 系统 SHALL NOT 自动再弹建议窗。
3. WHEN 访客在弹窗选择「切换」，THEN 系统 SHALL 导航到建议 locale 的同等首页路径且后续请求 `lang_local` 为建议语。
4. IF `navigator.language` 映射结果不在 L1，THEN 系统 SHALL NOT 弹窗且页面可正常浏览。

**US-2**

5. WHEN 目标站 L1 ≥200，THEN `<w:i18n:switcher />` 目录（或等价 catalog API）SHALL 暴露 ≥200 可选项（抽检 count）。
6. WHEN 抽检 ≥3 个非中英 L1 locale 打开首页，THEN 弹窗/顶栏语言相关可见文案 SHALL 为该目标语（≠ 中文 source）。
7. IF 官方模块交付，THEN `i18n/` SHALL 仅含中英 CSV，非中英语种 SHALL 来自系统词典而非模块多 locale CSV。

**US-3**

8. WHEN 运维持有启用清单 CSV，THEN 可核对至少 200 行 locale_code 与站启用集一致（导入或对账流程由架构定义，验收看结果集）。

## 9. 真机用例（可验收）

本机 Host：`http://{project_hash}.test.weline.com:{port}/` + FPC 穿章；Browser：禁缓存、抹 `navigator.webdriver`；**禁止**原生 `alert`。

| id | 名称 | 角色 | 前置 | 主成功步骤 | 备选/异常 | 期望结果 | 映射 acceptance |
|----|------|------|------|------------|-----------|----------|-----------------|
| UC-1 | 首次建议弹窗并切换 | 访客 | 清除 `lang_pref_resolved`；浏览器语言可映射到 L1 且 ≠ 默认当前语 | 1. 打开官网首页 2. 断言 `dialog[data-w-component=dialog]` 打开 3. 点击「切换」 4. 断言 URL/页面 `lang_local`=建议语 5. 再开首页 | 无 | 第二次不弹；文案为建议语 | `acc-e2e-suggest-switch` |
| UC-2 | 保持当前后不再提示 | 访客 | 同 UC-1 前置 | 1. 打开首页见弹窗 2. 点「保持当前」或关闭 3. 刷新/再进首页 | 无 | 不再自动弹；语言保持原当前语 | `acc-e2e-suggest-dismiss` |
| UC-3 | 映射不在 L1 不弹 | 访客 | 清除偏好；模拟浏览器语言为未启用 tag | 1. 打开首页 | 无 | 无建议 dialog；页面 200 | `acc-e2e-suggest-no-match` |
| UC-4 | 禁止原生对话框 | 访客 | 任意触发路径 | 1. 跑 UC-1 过程监听 `dialog`/`alert` | 若出现 `window.alert`/`confirm` | **FAIL** | `acc-e2e-no-native-dialog` |
| UC-5 | L1≥200 与 switcher | 访客/运维 | 目标站已导入 ≥200 语种 | 1. 打开首页 2. 打开语言切换器 3. 断言可选项 ≥200 | catalog API 等价断言可作辅证，不能替代 WB 打开 | count≥200 | `acc-e2e-locale-count-200` |
| UC-6 | 非中英首页词抽检 | 访客 | 选 ≥1 非中英 L1（如 `ru_RU` 或站内实际存在者） | 1. 切到该语首页 2. 断言弹窗或顶栏语言 chrome 译文 ≠ 中文 source | 词典未 publish → FAIL 并记缺口 | 目标语可见 | `acc-wb-home-locale-sample` |

计划阶段须 `acceptance[].covers_use_cases` 覆盖 UC-1…UC-6；收口前全 `passed`+证据。

## 10. 框架映射要点（给领域探查/架构 · 非实现清单）

| 能力 | 已有框架面 | 本需求增量 |
|------|------------|------------|
| 语言切换 UI | `<w:i18n:switcher />` / `LanguageSwitcher` | 复用切换导航；新增「建议」dialog，不平行造第二套 switcher |
| 站语种边界 | `LocaleCatalogScopeResolver` + `websites.getWebsiteLanguageCodes` | L1 扩到 ≥200；空站禁止回退全球目录（已有硬边界） |
| 弹窗原语 | `Weline.UI.dialog` | 建议窗必须走此原语 |
| 语言检测事件 | `DetectLanguage` | 保持「是否存在」语义；建议逻辑另建，勿塞业务硬编码进 Runtime |
| 译文明细 | 模块中英 CSV + 系统词典 | 首页词条按规范落盘；启用清单 CSV 运维面 |
| 目录库存 | `data/locale-catalog`（≈682 locales） | 仅 L3 |

## 11. 非目标 / not_to_do

1. **禁止** `window.alert` / `confirm` / `prompt`。
2. **禁止**在官方框架模块 `i18n/` 提交 ≥200 个非中英 locale CSV。
3. **禁止**把站品牌首页种子 / CatalogSeeder 写入 `app/code` 模块（`website_concept_seed_not_in_modules`）。
4. **禁止**本需求改长安汉服 / DaoCharms 等兄弟站语种集（除非用户改范围）。
5. **禁止**自动翻译全站所有模块全部词条到 200 语——范围仅 **首页可见词条集 + 弹窗 chrome**；其余漏译另开翻译工程师巡检需求。
6. **禁止**为本需求启动 Ollama（用户未明示时）。
7. **禁止**手改 `generated/**`。
8. **禁止**用 L3「目录有 682」冒充 L1/L2 交付完成。
9. **不**做后台管理「一键启用 200」的完整新后台产品（若仅 CLI/导入即可，架构选定；完整 Admin CRUD 扩面另议）。
10. **不**改支付/结账/购物车通路；非电商顾问强制触发（首页运营意图若顾问要表态，由 PM 决定是否加席）。

## 12. 澄清记录

| # | 问题 | 决议（本席默认） | 状态 |
|---|------|------------------|------|
| Q1 | 官网 = ID_DEFAULT 还是具名 aiweline website_id？ | 默认 A：`ID_DEFAULT`；与本机汉服默认站冲突时请用户选 B | **待用户拍板** |
| Q2 | 弹窗触发：首次 vs 无 cookie vs 语不一致？ | 无 `lang_pref_resolved` ∧ 映射∈L1 ∧ ≠当前语 | 已钉 |
| Q3 | 弹窗 UI？ | 仅 Weline.UI.dialog | 已钉 |
| Q4 | 「200」与 CSV？ | L1≥200 + L2 首页词条（中英 CSV+词典）+ 启用清单 CSV；L3 不计数 | 已钉 |
| Q5 | 映射落点？ | Weline_I18n；精确→语言+地区→语言→空 | 已钉 |

## 13. 架构隐患 / 周边发现（汇报 · 不擅自扩修）

1. **架构级**：本机 `website_id=0` 与生产「官网」身份可能不一致——范围选错会改错站语种集。  
2. **架构级**：官方模块 CSV Git 瘦身 vs 用户口糊「全语种 CSV」——须按 §4 分层，否则会违规范或撑爆 Git。  
3. **架构级**：L1=200 会放大 LanguageSwitcher catalog / FPC 维度；须性能席在方案期表态（懒加载 catalog 已存在，仍要冷路径预算）。  
4. **补丁级现状**：无现成「浏览器语言建议弹窗」成品；仅有 switcher 与「申请支持语言」dialog——勿误复用申请流。  
5. **补丁级**：`DetectLanguage` 名似检测浏览器，实为安装激活校验——命名易误导后续施工。

是否顺带处理由用户/PM 裁决。

## 14. 建议编制触发（供 PM）

- 必触发：领域探查（并行）、架构师、扩展点、前端、UI、原型、主题开发工程师、翻译工程师、Websites/后端（启用集）、测试、文档工程师；热路径 → 性能检查工程师。  
- 电商顾问：仅首页运营 brief 需要时（本席默认 **skip**，除非 PM 认为官网首页运营意图需顾问 stance）。

## 15. 就绪检查

- [x] `status` = `clarified`（关键 Q1 待拍板；其余已钉，可进对齐冻结草案）
- [x] ≥1 用户故事 + 每故事 ≥2 EARS
- [x] ≥1 用例（本规格 6 条）
- [x] 每条 UC 已填映射 acceptance id
- [x] 非目标明确
- [x] feature：将产生 e2e / WB-OP（`acc-e2e-*` / `acc-wb-*`）
- [x] 未写死类名级施工步骤
- [ ] 用户拍板 Q1 后可升 `ready-for-plan`（若 PM 接受默认 A，可代升）

## 16. 下一闸门

`clarified` → 领域探查闭环 → 架构方案（mechanism + owning_module + not_to_do）→ align-freeze（冻 UC+contracts）→ 施工。

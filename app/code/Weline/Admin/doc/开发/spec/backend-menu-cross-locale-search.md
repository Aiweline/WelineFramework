---
status: validated
work_kind: feature
feature_slug: backend-menu-cross-locale-search
module: Weline_Admin
updated: 2026-09-20
---

# 后台菜单多语言交叉搜索

## 澄清记录

| # | 问题 | 答案 | 时间 |
|---|------|------|------|
| 1 | 交叉搜索范围（侧栏 / 顶栏万能搜索） | **都要**：侧栏「搜索菜单」+ 顶栏 `<w:search area="backend">` | 2026-03-24 |
| 2 | 参与交叉的语言集合 | **后台已启用的全部 locale** | 2026-03-24 |
| 3 | 是否匹配 source 原文 / source_id | **额外未翻译的用默认（source 原文）回退**；不强制另匹配 source_id | 2026-03-24 |
| 4 | 命中后展示语言 | **同意**：仍只显示当前 UI 语言标题，不切语言 | 2026-03-24 |

## 目标 / 非目标

**目标**

- 后台多语言环境下，管理员用「非当前界面语言」的菜单名关键字，仍能在**侧栏菜单搜索**与**顶栏万能搜索**中找到对应菜单项。
- 例如：界面为中文时搜 `Products` 可命中「商品」；界面为英文时搜「商品」可命中 `Products`。
- 某 locale 无独立译文时，该语言位回退为 source 原文（默认标题）。

**非目标**

- 不改变菜单树结构、权限过滤、路由高亮逻辑。
- 不强制切换站点/后台语言。
- 不把配置字段检索塞回侧栏（配置仍走顶栏 SystemConfig Searcher）。
- 不要求匹配 `source_id` 路径串（除非其恰好等于可搜标题/原文）。

**角色**

- 后台已登录管理员（具备对应菜单 ACL）。

**成功标准**

- 当前语言下，用其它已启用语言的菜单译文关键字搜索，侧栏与顶栏都能命中同一菜单节点。
- 展示文案仍为当前语言；搜索仅扩展可匹配词。
- 无译文 locale 回退 source 原文后仍可被搜到。

## 用户故事

- 作为后台管理员，我想用任意已启用语言的菜单名（含未译时的默认原文）搜索侧栏或顶栏，以便在切换语言后仍能快速定位入口。

## EARS

1. WHEN 管理员在后台侧栏菜单搜索框输入其它已启用语言的菜单译文关键字，系统 SHALL 过滤出标题在当前语言下对应的同一菜单项。
2. WHEN 管理员在后台顶栏万能搜索输入其它已启用语言的菜单译文关键字，系统 SHALL 返回对应菜单命中并可导航。
3. WHEN 搜索关键字仅匹配其它语言译文，系统 SHALL 仍以当前界面语言显示菜单标题（不切换语言）。
4. IF 某菜单在某已启用 locale 无独立译文，THEN 该 locale 的可搜词 SHALL 回退为 source 原文（默认标题）。
5. WHEN 搜索框为空（侧栏），系统 SHALL 恢复完整菜单树与路由高亮行为（与现网一致）。
6. IF 角色无某菜单 ACL，THEN 任意语言关键字 SHALL 不暴露该菜单。

## 用例

### UC1 主成功：中文界面侧栏搜英文菜单名

1. 后台语言为 `zh_Hans_CN`。
2. 侧栏「搜索菜单」输入某菜单英文译文。
3. 期望：对应中文菜单项可见且可点击。

### UC2 主成功：英文界面顶栏搜中文菜单名

1. 后台语言为 `en_US`。
2. 顶栏万能搜索输入中文菜单名。
3. 期望：结果列表出现该菜单（英文标题展示），点击可导航。

### UC3 备选：无其它语言译文回退原文

1. 某菜单仅有 source 原文、无其它 locale 译文。
2. 用 source 原文搜索。
3. 期望：侧栏与顶栏均可命中。

### UC4 异常：无权限菜单

1. 角色无某菜单 ACL。
2. 用任意语言搜该菜单名。
3. 期望：侧栏与顶栏均不出现。

## 前后端范围

| 层 | 触点 | 是否改 |
|----|------|--------|
| BE | `Weline_Admin` `MenuRenderService`：渲染写入多语言 `data-search-text` | 是 |
| BE | `Weline_Admin`（或既有菜单 Search Provider）：顶栏 backend area 索引含全 locale 可搜词 | 是（若无 Provider 则新增） |
| FE | `Theme` `nav-filter`：已支持 `data-search-text`，匹配算法一般不变 | 否（除非缺契约测试） |
| i18n | 模块 CSV + Phrase 词条预取（禁整本 generated locale） | 读 |

## UI 技能决策

- `ui_skill_decision=skip`：无布局/视觉改版，仅搜索匹配词扩展。

## 架构要点（Plan Mode 被拒后的会话内方案）

1. **共享**：抽出「菜单标题 → 全启用 locale 可搜词（缺译回退 source）」构建逻辑，供侧栏 HTML 与顶栏 Provider 复用。
2. **侧栏**：`renderMenuNode` 给每个 `w-backend-nav__entry` 写 `data-search-text`（去重、小写友好、含当前语+其它语+source）。
3. **顶栏**：确保 backend Menu Searcher 的 `search_text` / 索引字段含同一套多语言词；命中 title 仍为当前 locale。
4. **词典（硬）**：跨语词条只走模块 `i18n/{locale}.csv` + `Parser::prefetchWords` / `Parser::getPrefetchedGlobalWord`（词条级 Worker/Shared）。**禁止** `include generated/language/{locale}.php` 整本词典。
5. **验收**：UT 契约 + 本机 Browser 侧栏/顶栏交叉搜；feature e2e 章通路。

## i18n 读路径

- 启用 locale 列表：`ActiveLocaleCodeProvider::getInstalledActiveCodes()`。
- 渲染前对菜单标题集合按各 active locale **一次** `Parser::prefetchWords($titles, $locale)`。
- `resolveMenuTitleRaw`：模块 CSV → Phrase 预取词条 → 回退 source。

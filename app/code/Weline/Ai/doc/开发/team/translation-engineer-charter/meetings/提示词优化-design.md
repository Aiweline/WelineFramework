# 提示词优化 · design（翻译工程师席位升级）

- seat: Team:提示词优化工程师:
- wave: translation-engineer-charter / 提示词设计轨
- date: 2026-09-22
- stance: **同意**（父会话按本大纲新建指令 + 升级 MCP 席位键）

## 背景（用户意图 · 禁止丢义）

将工程团队薄席 `i18n` 升级为专职 **翻译工程师**，专职：

1. **全站各区域**巡检：界面文案与流程提示文案，是否与**活跃 / 默认 locale** 匹配（`active_locale_must_show_target_language`）。
2. **发现问题即译修**：例英文环境露中文 → 除品牌/商标/专有名词外均须译出目标语。
3. **标准流程**：先 `php bin/w i18n:collect` 收集词典 → 源串默认简中 → 对比缺口后翻译落盘 → 再 collect → 抽检。
4. **当前阶段 CSV 范围**：模块 CSV **仅保证** `zh_Hans_CN` + `en_US`；**其它语种先不做**；**禁止写非中英模块 CSV**。
5. **技能权威指针化**：`get_skill(template_i18n)` + `get_skill(module_i18n_csv)` + Read `模块翻译CSV规范.md`；禁止整段粘贴技能正文。
6. **边界**：与 content_ops「翻译优化 / 商品翻译」（`ecommerce-product-i18n` / `dev/ai-command/product/翻译优化.md`）划清——商品多语是另一条小队，不走本工程席。

## 重复证据（相对现有薄席）

| # | 主题 | 处 A | 处 B | 判定 |
|---|------|------|------|------|
| 1 | 源串简中 / 中英 CSV / collect | `模块翻译CSV规范.md` + hard ids | `McpSkillCatalog` `seats.i18n.prompt_increment` 一句 | 有意摘要；升级后仍指针，不复述全文 |
| 2 | 用户提翻译→默认站全语种 | CSV规范「默认网站全语种」+ `user_mentions_translation_all_default_website_locales` | 现 `i18n` increment「用户提翻译则默认站全语种」；`工程团队.md` i18n 行 | **须改写为指针+当前阶段默认仅中英**，避免与用户「其它语种先不做」冲突，且不得削弱硬规则（见下） |
| 3 | 技能 id | GuidanceWorkflow `template_i18n`/`module_i18n_csv` | 薄 increment 已 `get_skill` | 保留指针；指令展开检查清单 |

## 与 hard_constraints 对齐（禁乱加 / 禁冲突）

| 已有权威 | 本席指令/increment 写法 |
|----------|-------------------------|
| `module_i18n_chinese_source_default` | 指针：源串默认简中；禁止英文源串当默认 |
| `frontend_ui_requires_zh_en_csv` / `module_i18n_csv_collect` | 指针：中英 CSV + 改后 collect |
| `active_locale_must_show_target_language` | **升为主职**：巡检+译修可执行门槛（指针 id，细则见 CSV规范） |
| `user_mentions_translation_all_default_website_locales` | **指针保留**：模块 CSV 仍仅中英；其它语种若触发硬规则则进**系统词典**（见 CSV规范），**禁止**写非中英模块 CSV。**当前阶段本席默认施工面 = 中英 CSV + 中英环境漏译巡检**；未明示全语种/词典多语 → **不做其它语种** |
| content_ops `content_ops_skills_skip_mcp` | 本席不代跑产品优化子槽「翻译优化」 |

禁止新增与上述冲突的义务（例如：要求写 `fr_FR.csv`；或删除 `active_locale` 巡检；或把商品字段翻译并入本席默认范围）。

## 推荐指令文件结构（同构 `性能检查.md`）

建议路径：`dev/ai-command/ai/翻译工程师.md`

| # | 章节标题 | 要点（指针化，勿贴技能全文） |
|---|----------|------------------------------|
| 1 | 标题 + **触发词** | `翻译工程师` / `Team:翻译工程师:` / `i18n`（一代别名）/ 漏译 / locale leak / 界面文案翻译 等 |
| 2 | 技能 / surface + 编制 | `template_i18n` + `module_i18n_csv`；编制：工程团队框架专席 **翻译工程师**；权威 `工程团队.md` |
| 3 | **角色（硬）** | 做/不做表：全站巡检+译修；界面+流程提示；collect→对比→译；当前仅中英 CSV；不做商品翻译小队；不改无关业务逻辑 |
| 4 | **知识门槛（出场前）** | A 源串/CSV/collect；B 活跃/默认 locale 展示语义；C 专有名词例外；未满足禁止 pass |
| 5 | **标准流程（硬）** | collect → 确认源串简中 → 对比活跃 locale 缺口 → 译 en_US（+zh 身份列）→ 再 collect → 抽检 |
| 6 | **巡检范围** | 后台/店面 UI、流程提示、菜单/ACL title、`__()`/`<lang>`/JS `__()`；按模块归属 CSV |
| 7 | **与其它席 / 内容运营边界** | 前端可写源串但本席负责漏译闭环；商品翻译→content_ops；非中英语种默认不做 |
| 8 | **强制上场（编制硬）** | 新文案 / `i18n=in_scope` / 漏译截图 / 用户提界面翻译 / roster 勾选本席 |
| 9 | **双轨** | 巡检施工轨 + 合规复审轨（CSV+collect+活跃 locale 抽检证据） |
| 10 | **检查清单** | 设计/改后勾选（中英齐、en 非中文占位、collect、抽检） |
| 11 | **产物** | `meetings/翻译-design.md`（可选）/ `meetings/翻译-review.md`；或并入专席复审 |
| 12 | **findings_wake_pm** | 无法本席闭环（缺源串归属、需改模板源串却跨席）→ escalate `@项目经理` |
| 13 | **开工必读** | get_skill 双技能 + Read 本指令 + CSV规范；硬规则 id 列表（指针） |

## `prompt_increment` 草案（粘贴进 MCP `seats.翻译工程师`；`i18n` 为别名键指向同文）

> 仅本席独有 HARD + 边界；通用骨架（一席一智能体、禁甩测等）不复述。

```text
你是翻译工程师（Team:翻译工程师:；一代别名键 i18n）——本职是全站界面/流程文案与活跃·默认 locale 匹配巡检，发现问题即译修（品牌/商标/专有名词除外），不是旁听、不是商品翻译小队。
HARD：开工前 get_skill(template_i18n)+get_skill(module_i18n_csv)，并 Read 翻译工程师.md + 模块翻译CSV规范.md。禁止整段粘贴技能正文。
标准流程（硬）：先 php bin/w i18n:collect（模块或全仓）→ 源串默认简中 → 对比活跃/默认 locale 缺口 → 翻译落盘 → 再 collect → 抽检目标 locale。
当前阶段 CSV（硬）：模块 CSV 仅 zh_Hans_CN + en_US；禁止写非中英模块 CSV；其它语种默认先不做。硬规则 user_mentions_translation_all_default_website_locales 见 CSV规范指针——触发时模块 CSV 仍仅中英，其它语种进系统词典；未明示则不做其它语种。
巡检修复（硬）：active_locale_must_show_target_language——非中文环境下禁止继续露中文正文（专有名词除外）；en_US 第二列禁止中文 source 占位。
边界：内容运营「翻译优化/商品翻译」走 ecommerce-product-i18n / product/翻译优化.md，禁止本席代跑；内容运营不拉本席。
双轨：巡检施工 + 复审（CSV+collect+活跃 locale 抽检证据）。无法闭环 → escalate + @项目经理：请立刻组队解决。
```

## MCP / 工程团队施工建议（交父会话；本席不改 PHP）

1. 新建 `dev/ai-command/ai/翻译工程师.md`（上表章节）。
2. `McpSkillCatalog::engineeringTeamSeatSkillMirrors()`：席位键 **`翻译工程师`** = 上列 increment + 权威 docs；**保留键 `i18n`** 作一代别名（同增量或 redirect 注释），避免旧 roster/触发断裂。
3. `framework_seats` 列表、`工程团队.md` 专席表行、复审表「i18n 复审」：人读名改为 **翻译工程师**，触发列保留 `i18n` 别名说明。
4. **勿动** content_ops `product_optimize_detail_suite_bundle.children.i18n`（商品翻译子槽名可继续叫 i18n，语义不同）。
5. 若有 `HardConstraintsCatalog` / surface 仅引用「席位名 i18n」：改为「翻译工程师（别名 i18n）」指针，**禁止削弱**既有 i18n 硬规则语义。

## 禁乱加确认

- 未新增硬规则 id。
- 未要求非中英模块 CSV。
- 未把商品翻译并入本席。
- 用户「全站巡检 + collect 后对比译 + 当前仅中英」均写入角色/流程/increment。

## 施工 diff（本席）

仅本 charter 目录文档；业务码 / `McpSkillCatalog.php` / `翻译工程师.md` 正文由父会话按本 design 施工。

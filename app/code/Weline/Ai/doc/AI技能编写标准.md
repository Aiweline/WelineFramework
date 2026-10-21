# AI 技能编写标准（skill-v2）

**适用范围**：本仓所有 MCP 技能（`GuidanceWorkflowCatalog` surface / `SkillSurfaceV2` 覆盖文件）、`dev/ai-command/**` 指令、席位技能镜（`seat_skill_mirrors.prompt_increment`）、宿主薄壳 `SKILL.md`。

**权威关系**：`hard_constraints`（政策层，强制）＞ 本标准的技能结构（程序层）＞ 模块 `doc/`（知识层）＞ 宿主 `SKILL.md`（薄壳，非权威）。
技能不复制政策正文，只引用规则 id；政策不写操作步骤，只写边界。

**机器校验**：`php app/code/Weline/Ai/Mcp/tests/skill-surface-standard.php`。任何 `spec=skill-v2` 的技能超标即 FAIL。

---

## 1. 一个技能 = 一个可执行程序

技能的读者是模型，不是人类学员。合格的技能让模型在**不看其它文件**的情况下知道：

| 问题 | 字段 | 判定 |
|------|------|------|
| 什么时候该用/不该用 | `description` + `when` | 有触发词，有排除条件 |
| 需要什么输入 | `inputs` | 缺输入时先问，不猜 |
| 按什么顺序做 | `procedure` | 3–7 步，动词开头，可观测 |
| 怎样算做完 | `checklist` | 二值判定，能打 pass/fail |
| 哪些做法一定错 | `anti_patterns` | 写可观测症状，不写口号 |
| 交什么证据 | `evidence` | 文件路径 / 命令 / 截图 |
| 还有哪些规则在管 | `norm_ids` | **只放规则 id 指针**，不写摘要（正文由 hard_constraints 展开） |
| 深入去哪读 | `authoritative_docs` | 指针，不粘贴正文 |

**一句话标准**：删掉任一节后模型行为会变差；留着却不改变行为的段落就是无效内容。

## 2. 结构规范（渲染顺序）

```
# <label>
<description>                      ≤ 280 字，是什么 + 何时用，≤3 个规则 id
## 何时使用     when[]              3–6 条，含排除条件
## 输入         inputs[]            0–5 条
## 步骤         procedure[]         3–7 条，编号渲染
## 检查清单     checklist[]         3–8 条，二值
## 禁止         anti_patterns[] + template_surface_rules.forbidden[]
## 规范要点     norms[]             0–12 条，只放步骤装不下的硬约束
## 规则索引     norm_ids[]          0–60 条，只写规则 id（渲染成一行指针）
## 证据         evidence[]          1–4 条
## 验证         verification_commands[]
## 权威文档     authoritative_docs[] ≤10 条，必须真实存在
```

`template_surface_rules.required/forbidden` 保留为兼容别名；新内容写 `checklist` / `anti_patterns`。

## 3. 预算（硬上限）

| 项 | 上限 | 理由 |
|----|------|------|
| `description` | 280 字 | `resolve_skill` 索引与 greeting 会读它 |
| `when` | 6 条 / 每条 60 字 | 触发判断用，不写教程 |
| `procedure` | 7 步 / 每步 90 字 | 超过 7 步应拆技能 |
| `checklist` | 8 条 / 每条 60 字 | 每条必须能判真假 |
| `anti_patterns` | 8 条 / 每条 60 字 | 写现象，不写"要重视" |
| `norms` | 12 条 / 每条 200 字 | 只留步骤表达不了的约束 |
| `norm_ids` | 60 个 / 每个 80 字符 | 规则 id 指针，禁止写摘要 |
| `evidence` | 4 条 | 证据要可复核 |
| `authoritative_docs` | 10 条 | 每条都必须存在 |
| 正文合计 | 3600 字（≈2400 tokens） | `get_skill` 单次加载上限；中位数目标 ≤2000 |

超预算不是"再压一压"，而是**分层**：把细节移进权威文档，技能里只留指针与判据。

## 4. 写作规则

1. **动词开头，主语省略**：`读取…` / `写入…` / `对照…`；禁 `应该注意`、`建议尽量`。
2. **可观测**：`检查项 ≤ 8 且每条能判真假`；禁 `美观`、`合理`、`友好`等不可判定词。
3. **一处权威**：同一约束在 `hard_constraints` 展开一次，技能里写 `见 <rule_id>`；发现第二份同义展开即视为缺陷。
4. **指针优先**：引其它技能写 `get_skill(<id>)` + 必读路径，禁止粘贴对方正文（粘贴即产生第三份副本）。
5. **数字代替形容**：`8pt 基线`、`≤1 个强调色`、`≥3 级字号`；不用"适当""简洁"。
6. **反例要具体**：`卡片套卡片`、`字号全是 14px`；不写"避免混乱"。
7. **术语固定**：`原型`/`UI`/`主题开发工程师`/`审图` 等席名与命令名不改写、不缩写。
8. **中英边界**：正文中文；代码、规则 id、路径、命令保持原文，不翻译。
9. **不加新义务**：压缩/改写只允许合并同义副本，不得引入原文没有的流程、席位或硬规则。
10. **删前先证**：删除段落前必须能指出它被哪条规则/哪个文档覆盖；无覆盖则先补权威再删。

## 5. 反模式库（发现即改）

| 反模式 | 症状 | 处理 |
|--------|------|------|
| 政策倾倒 | 技能正文 = 硬规则摘要合集 | 改为 `见 rule_id`，正文只留步骤 |
| 复述三遍 | 骨架、席位镜、指令各存一份同义 checklist | 留一处权威 + 两处指针 |
| 口号段 | "高度重视""务必确保" | 删除，无信息量 |
| 教程腔 | 解释框架为什么这样设计 | 移入模块 `doc/`，技能里给路径 |
| 万能触发词 | triggers 含 `优化`、`开发` 等泛词 | 换成现象词/命令词 |
| 幽灵文档 | `authoritative_docs` 指向不存在的路径 | 补文档或删指针（lint 会报） |
| 假完成 | 技能没有 evidence 定义 | 必填 1–4 条可复核证据 |

## 6. 评审清单（改技能前跑一遍）

1. 这个技能解决的是**一个**任务吗？能否用一句"当…时，做…"说清？
2. 每条 `procedure` 都能被另一个模型一步执行吗？
3. 每个 `checklist` 项都能打 pass/fail 吗？
4. 有没有和 `hard_constraints` 重复的整段？→ 改成规则 id。
5. 有没有引用不存在的文档/技能？→ lint + `test -f`。
6. 删掉这段，模型会做错什么？答不上来 → 删。
7. 正文是否 ≤ 3600 字？
8. 触发词是否包含用户真实会说的话（中英各≥1）？
9. 反例是否具体到能截图/能在 diff 里看到？
10. `get_skill` 正文能否独立支撑一次完整执行？

## 7. 与 UI / 原型 / 品味技能的关系

视觉类任务固定加载顺序（`css_or_theme_requires_ui_prototype_theme_skills`）：

```
prototype（信息架构与线框）
  → design_taste（层级/审美判据）      ← 技能别称 taste
  → frontend_design（界面实现）
  → weline-theme-development（Token 与主题落地）
```

`design_taste` 只做**判断**（层级、密度、字号阶梯、强调色预算、文案声音），不产出 CSS，不替代 Theme Token。
它的权威文档是 `app/code/Weline/Theme/doc/设计品味.md`。

## 8. 版本与迁移

- `spec: skill-v2` —— 已按本标准改造，受 lint 约束。**当前 25 个 surface 全部迁移完毕（legacy=0）。**
- 未标注者 —— 遗留技能，允许临时超标，但**只减不增**：每次触碰必须顺手迁移。
- 迁移顺序：视觉/前端链路 → 工程团队与验收 → 专席技能 → 支撑技能。
- 迁移完一个技能，在提交说明里写明：`skill-v2 <surface_id> desc -X% norms -Y`。

### 迁移记录（2026-10）

| 指标 | 迁移前 | 迁移后 | 变化 |
|------|--------|--------|------|
| surface 数 | 24（全 legacy） | 25（+`design_taste`） | 25 skill-v2 / 0 legacy |
| 正文总量（估算字） | 81,835 | 60,491 | **−26%** |
| 单技能均值 | 3,273 | 2,420 | −26% |
| 最大技能 | engineering_team 12,154 | engineering_team 3,573 | **−71%** |
| 第二大 | frontend_development 12,089 | frontend_development 3,545 | **−71%** |
| theme_development | 7,579 | 3,581 | −53% |
| 结构完整性 | 多数无 procedure/checklist/evidence | 全部含 3–7 步 + 3–8 检查项 + 证据定义 | 可执行化 |
| 规则 id 覆盖 | 以摘要正文重复政策 | `norms` ≤12 摘要 + `norm_ids` 指针索引 | 单一权威 |

> 小技能（event/hook/module_upgrade 等）字数**上升**是预期：它们原来只有一句描述 + 1–2 条 norm，几乎没有可执行程序；v2 补上了 when/procedure/checklist/evidence。

迁移方法（可复用）：老 surface 函数留在 `GuidanceWorkflowCatalog` 作回退源；新正文写 `src/SkillSurfaceV2/{id}.php`，由 `SkillSurfaceV2::apply()` 覆盖合并。禁止直接改老函数冒充迁移。

## 9. 度量

```bash
# 全量技能预算体检（lint，CI 门禁用）
php app/code/Weline/Ai/Mcp/tests/skill-surface-standard.php

# 预算报表（字数/结构/lint 一屏看完）
php app/code/Weline/Ai/Mcp/scripts/skill-metrics.php
php app/code/Weline/Ai/Mcp/scripts/skill-metrics.php --json

# 单技能正文字数 / 结构
php -r 'require "app/code/Weline/Ai/Mcp/src/bootstrap.php";
$s=LearningMcp\McpSkillCatalog::get("design_taste",true);
echo mb_strlen($s["content"]),"\n";'

# 遗留技能余量（应为 0）
php -r 'require "app/code/Weline/Ai/Mcp/src/bootstrap.php";
print_r(LearningMcp\SkillSurfaceV2::legacySurfaceIds());'
```

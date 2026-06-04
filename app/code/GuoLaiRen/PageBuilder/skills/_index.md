# AI 建站工作台技能索引（PageBuilder Skills）

> 状态：active
> 模块：`GuoLaiRen_PageBuilder`
> 注册表实现：`app/code/GuoLaiRen/PageBuilder/Service/AI/AiSiteSkillRegistry.php`

## 用途

让 AI 建站工作台的提示词具备**显式技能加载能力**：

- 第一阶段方案（`AiSitePlanJsonGenerationService::buildAiPlanPrompt`）默认加载本目录下的强制技能；
- 第二阶段任务（`AiSitePlanJsonService` -> `AiSiteSkillRegistry::buildPromptGuideLines`）默认加载本目录下的强制技能 + 兼容既有版 `frontend-design`；
- AI 在产出 plan/task 时必须把这些技能视为已装载并执行其硬约束。

## 技能目录约定

每个子目录是一项可加载技能，必须满足：

- 目录名即 `skill_code`，仅小写字母、数字、连字符；
- 包含一份 `SKILL.md`，顶部为 YAML frontmatter，至少含：
  - `name`：技能短名（提示词中作为标识展示）；
  - `description`：能力说明（自动压缩并写入提示词的 summary）。
- 其他 `references/`、`assets/`、`demos/` 等为可选辅助目录，仅供人查阅、不会自动注入。

## 默认加载技能（写死在 `AiSiteSkillRegistry::DEFAULT_SKILL_CODES`）

| code | 用途 | 关键约束 |
| --- | --- | --- |
| `claude-design` | 设计纪律、反 AI-slop、内容真实性、craft 规则 | 系统先行、ground in real context、避免 gradient orb / 三栏特性网格 / SVG 假产品图 / Inter 默认字体；不得拼凑 filler 段；用户语气与一句话需求落地 |

## 兼容技能（沿用 `prompt_guides/frontend-design`）

- 路径：`app/code/GuoLaiRen/PageBuilder/Service/AI/prompt_guides/frontend-design/SKILL.md`
- 加载点：第二阶段每批组件任务前置注入；
- 角色：保留原有的 `style_plan / responsive_contract / accessibility_contract` 注入逻辑，不替代 `claude-design`。

## 提示词注入流程

```
用户一句话需求
   ↓
buildAiPlanPrompt（Stage1）
   ├─ CONCRETENESS CONTRACT
   ├─ AI BUILDER SKILL CAPABILITY（默认加载技能列表 + 协议）
   ├─ CLAUDE-DESIGN HARD RULES（精炼自 SKILL.md）
   └─ STAGE-1 SHARED THEME PLAN CONTRACT
   ↓
（用户确认 stage1 plan）
   ↓
planJsonTaskPlanPromptBase（Stage2）
   ├─ 上下文摘要
   ├─ buildSkillRegistryPromptGuide
   │     ├─ AI BUILDER SKILL CAPABILITY
   │     ├─ CLAUDE-DESIGN HARD RULES
   │     └─ FRONTEND-DESIGN 兼容指引
   └─ Stage2 任务 schema
```

## 新增技能流程

1. 在 `app/code/GuoLaiRen/PageBuilder/skills/<your-code>/SKILL.md` 写好 frontmatter（`name` + `description`）；
2. 如需默认加载，编辑 `AiSiteSkillRegistry::DEFAULT_SKILL_CODES` 把 `<your-code>` 加入；
3. 在本文件追加技能行；
4. 跑 `php -l` 与相关单测；
5. 在 `doc/建站中台/AI建站中台-计划.md` 的 §14 增加“技能新增/变更”记录。

## 不做的事

- 不会自动把整份 SKILL.md 内容塞到 prompt（避免膨胀），只摘 frontmatter 与 PHP 中精炼的硬约束；
- 不会在 stage3（虚拟主题构建）二次注入；stage3 由 PageBuilder 模板兼容层负责，不依赖此注册表；
- 不会用作运行时缓存（除进程内 `self::$skillCache`），重新启动 WLS 即可刷新。

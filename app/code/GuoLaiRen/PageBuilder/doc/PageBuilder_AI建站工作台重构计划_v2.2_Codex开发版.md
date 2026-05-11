# PageBuilder AI 建站工作台重构计划 v2.2：强契约 + premium_web_v1 底座 + Codex 开发版

> 状态：architecture / implementation plan  
> 范围：`app/code/GuoLaiRen/PageBuilder`  
> 目标：保留“方案1”的产品形式，但让“方案1”本身就是可执行 BuildPlanContract，默认省略第二阶段任务方案。  
> 核心原则：**规划只做一次；解释不进入构建；方案就是任务图；Build 只执行；QA 只校验；Repair 只 patch 允许字段。**

---

## 0. 给 Codex 的执行边界

Codex 按本文开发时，必须遵守以下硬边界：

1. **不要删除旧流程代码。** 旧的 `AiSiteTaskPlanQueue`、`execution_blueprint`、`virtual_theme_plan` 需要保留 legacy 兼容入口。
2. **不要把 Stage2 继续做成默认流程。** 默认链路只能是：`Plan v2.2 -> 用户确认一次 -> BuildQueue 执行 task graph`。
3. **不要把完整底座提示词塞进每个 Build 任务。** `premium_web_v1` 是设计策略源，Build 只能读取当前 task 所需的 policy slice。
4. **不要让 Build 重新规划。** Build 只能读取当前 task、当前 block、当前 page、design manifest 和依赖产物。
5. **不要在 Controller / SSE / 浏览器请求生命周期里执行长队列。** Controller 和 SSE 只创建队列、读取状态、推送事件；队列执行仍由框架 queue runner / 调度器负责。
6. **不要让 AI 输出解释性字段进入 Contract。** 禁止 `reason`、`why`、`rationale`、`thinking`、`analysis`、`explanation`、`设计原因`、`为什么` 等字段。
7. **不要一次性重构所有东西。** 先做 P0/P1，把 `BuildPlanContract v2.2`、linter、PlanQueue 输出跑通；再改 BuildQueue。
8. **不要把前端方案展示做成另一份 AI 文案。** 前端方案必须由 `BuildPlanContract v2.2` 投影出来，避免“展示方案”和“构建任务”两套内容不一致。

---

## 1. 一句话结论

当前问题不是“提示词不够强”，而是：

```text
链路太长
重复规划太多
上下文噪音太多
Stage1 / Stage2 / Build 三次理解导致漂移
```

目标重构为：

```text
用户一句话需求
-> AiSitePlanQueue 生成 BuildPlanContract v2.2
-> Plan Linter 校验
-> 前端展示“方案1”投影
-> 用户确认一次
-> BuildPlanTaskScheduler 创建后台任务
-> AiSiteAssetQueue / AiSiteBuildQueue 按任务图执行
-> QA / Repair
-> Preview / Publish
```

也就是：

```text
方案1 = 可执行任务图 = BuildPlanContract v2.2
```

默认省略：

```text
Stage2 任务方案生成
```

`AiSiteTaskPlanQueue` 降级为：

```text
legacy only / manual deep refine / debug / 超复杂项目兜底
```

---

## 2. 当前仓库事实

PageBuilder 模块位置：

```text
app/code/GuoLaiRen/PageBuilder
```

当前模块目录已包含：

```text
Api/
Console/
Controller/
Cron/
Helper/
Http/Sse/
Model/
Observer/
Queue/
Service/
doc/
docs/
etc/
extends/module/
i18n/
skills/
test/
view/
```

当前队列目录已包含：

```text
Queue/AiSiteAgentForQueue.php
Queue/AiSiteAssetQueue.php
Queue/AiSiteBuildQueue.php
Queue/AiSitePlanQueue.php
Queue/AiSiteTaskPlanQueue.php
```

当前契约相关目录已包含：

```text
Service/AI/Contract/BlockRecipeRegistry.php
Service/AI/Contract/ContractMetaBuilder.php
Service/AI/Contract/ContractPatchValidator.php
Service/AI/Contract/ContractQaReportBuilder.php
Service/AI/Contract/ContractType.php
Service/AI/Contract/LegacyContractAdapter.php
Service/AI/Contract/PermissionMatrix.php
Service/AI/Contract/QaGateHelper.php
Service/AI/Contract/SourceContractHelper.php
Service/AI/Contract/SourceTruthContractBuilder.php
Service/AI/Contract/SourceTruthContractValidator.php
Service/AI/Contract/SourceTruthCoverageLinter.php
Service/AI/Contract/VisualAssetUsageValidator.php
Service/AI/Contract/VisualContractQaLinter.php
```

当前模型目录已包含会话、产物、事件、技能等模型：

```text
Model/AiSiteAgentSession.php
Model/AiSiteAgentSessionArtifact.php
Model/AiSiteAgentSessionEvent.php
Model/AiSiteSkill.php
Model/Page.php
Model/Page/LocalDescription.php
Model/VirtualTheme.php
Model/VirtualThemeComponent.php
Model/VirtualThemeLayout.php
```

当前 Service 目录里已有大量可复用服务，不应无限新增重复职责服务，优先复用：

```text
AiSiteAgentSessionArtifactService.php
AiSiteAgentSessionService.php
AiSiteAssetManifestService.php
AiSiteAutoAssetGenerationService.php
AiSiteBuildTaskService.php
AiSiteExecutionBlueprintService.php
AiSiteHtmlBlocksBuildService.php
AiSiteMaterializationService.php
AiSitePageBlueprintService.php
AiSiteQualityGateService.php
AiSiteQueueSnapshotService.php
AiSiteTaskPlanSseService.php
AiSiteVirtualThemePlanService.php
AiSiteVirtualThemeService.php
AiSiteAntiHardcodeScanService.php
AiHtmlSanitizerService.php
```

---

## 3. 当前失败原因

旧链路大致是：

```text
用户一句话
-> Stage1 扩写方案
-> 用户确认
-> Stage2 再拆任务
-> 用户确认
-> Build 再根据任务生成网站
```

失败原因：

```text
Stage1 做了一次理解
Stage2 又做一次理解
Build 又做第三次理解
```

每一阶段如果都带有大量解释性内容，例如：

```text
为什么这样设计
每个区块的原因
风格分析
审美解释
策略说明
模型思考
```

后续阶段会被这些噪音干扰。最终表现为：

```text
契约字段看起来强
但上下文语义是乱的
Build 仍然会偏离目标
```

真正要做的是：

```text
第一阶段只生成可执行事实，不生成解释性废话。
```

---

## 4. 目标架构

### 4.1 目标流程

```text
IntakeNormalizer
    ↓
AiSitePlanQueue
    ↓
BuildPlanContract v2.2
    ↓
BuildPlanContractValidator
BuildPlanTaskGraphValidator
BuildPlanNoReasonLinter
BuildPlanDesignPolicyLinter
ContentManifestLinter
    ↓
plan_ready
    ↓
用户确认方案1
    ↓
BuildPlanTaskScheduler
    ↓
AiSiteAssetQueue / AiSiteBuildQueue
    ↓
AiSiteQualityGateService
    ↓
RepairPatchQueue / ContractRepairExecutor
    ↓
preview_ready / published
```

### 4.2 新状态机

推荐状态：

```text
draft
planning
plan_linting
plan_ready
plan_confirmed
scheduling_tasks
building_assets
building_blocks
assembling_pages
i18n_generating
seo_generating
qa_running
repairing
preview_ready
published
failed
```

legacy 状态仅保留兼容：

```text
legacy_stage2_pending
legacy_stage2_ready
legacy_stage2_confirmed
```

默认 UI 不展示 legacy 状态，除非：

```text
旧会话
调试模式
用户显式点击“深度细化方案”
超复杂项目兜底
```

---

## 5. premium_web_v1 底座策略的工程化定位

### 5.1 核心判断

“高级网站底座提示词”不能被当成一整段长 prompt 每次塞给 Build。它应该变成一个工程化设计策略：

```text
design_policy_id = premium_web_v1
```

它负责三件事：

```text
默认生成高级感
用户有明确想法时优先服从用户
用户不懂设计时自动补齐审美、间距、排版、动效、图片融合、响应式等细节
```

### 5.2 底座在系统中的分层

| 层级 | 名称 | 是否进入 Build 上下文 | 用途 |
| --- | --- | --- | --- |
| 策略源 | `premium_web_v1.full_policy_prompt` | 否 | 产品维护、Stage1 生成时可用 |
| 紧凑策略 | `premium_web_v1.compact_policy_prompt` | 仅必要时 | token 紧张时使用 |
| 策略引用 | `policy_ref` | 是 | 记录当前使用策略 id/version/hash |
| 策略投影 | `policy_projection` | 是 | Stage1 把底座压缩成规则 ID 和质量线 |
| 设计清单 | `design_manifest` | 是 | 已落地的颜色、间距、排版、动效、响应式 tokens |
| 区块配方 | `blocks.*.recipe` | 是 | 让 Build 不从零设计 |
| 任务切片 | `tasks.*.policy_slices` | 是 | 当前任务需要读取的底座规则 |
| 任务验收 | `tasks.*.acceptance_rule_ids` | 是 | 可检查的验收规则 |
| Linter | `BuildPlanDesignPolicyLinter` | 否 | 检查底座是否正确投影 |

核心原则：

```text
底座保审美，用户给方向。
```

用户明确要求优先，但默认底座必须兜住质量底线：

```text
统一间距
清晰视觉层级
克制配色
精致排版
图片与模块融合
背景有层次但不干扰阅读
动效细腻克制
响应式良好
可访问性合格
组件风格统一
文案真实自然
```

---

## 6. premium_web_v1 策略注册表

新增或改造服务：

```text
Service/AiSiteDesignPolicyRegistry.php
Service/AiSiteDesignPolicyPromptBuilder.php
```

### 6.1 `AiSiteDesignPolicyRegistry` 职责

返回固定策略，不让 AI 自由发明底座规则。

建议结构：

```php
final class AiSiteDesignPolicyRegistry
{
    public function get(string $policyId = 'premium_web_v1'): array
    {
        return [
            'policy_id' => 'premium_web_v1',
            'version' => '1.0.0',
            'hash' => 'sha256:...',
            'priority_rules' => [...],
            'quality_floor' => [...],
            'rule_catalog' => [...],
            'default_tokens' => [...],
            'default_recipes' => [...],
            'banned_patterns' => [...],
            'full_policy_prompt' => '...',
            'compact_policy_prompt' => '...',
        ];
    }
}
```

### 6.2 `policy_ref`

Contract 中只保存轻量引用：

```json
{
  "policy_ref": {
    "policy_id": "premium_web_v1",
    "policy_version": "1.0.0",
    "policy_hash": "sha256:...",
    "source": "AiSiteDesignPolicyRegistry"
  }
}
```

### 6.3 `policy_projection`

Stage1 必须把底座投影成可执行规则，而不是把完整 prompt 原文塞进 Contract。

```json
{
  "policy_projection": {
    "applied_rule_ids": [
      "priority.user_requirements_first",
      "layout.4_8_spacing",
      "visual.clear_hierarchy",
      "image.integrated_not_pasted",
      "motion.subtle_transform_opacity",
      "responsive.no_horizontal_scroll",
      "a11y.alt_focus_semantic"
    ],
    "user_overrides": [
      {
        "field": "style",
        "value": "科技感暗黑风",
        "handled_by": "design_manifest.style_name"
      }
    ],
    "quality_floor": [
      "页面美观",
      "层级清晰",
      "间距统一",
      "图片融合",
      "响应式良好",
      "不廉价、不杂乱、不像模板拼接"
    ],
    "banned_rule_ids": [
      "ban.reason_fields",
      "ban.random_spacing",
      "ban.too_many_colors",
      "ban.hard_black_shadow",
      "ban.image_module_split",
      "ban.lorem_ipsum"
    ]
  }
}
```

### 6.4 规则 ID 目录

建议至少固定以下规则 ID：

```text
priority.user_requirements_first
priority.user_style_first
priority.default_premium_when_unspecified
priority.optimize_bad_user_visual_request

layout.grid_alignment
layout.4_8_spacing
layout.container_1120_1280
layout.section_padding_desktop_96_140
layout.section_padding_mobile_56_80
layout.card_padding_24_40
layout.body_width_560_720

typography.refined_font_stack
typography.h1_clamp
typography.h2_clamp
typography.body_16_18
typography.body_line_height_165_180
typography.cn_letter_spacing_safe
typography.en_title_negative_tracking

color.max_2_3_primary_colors
color.low_saturation
color.no_neon_unless_user_requests
color.readable_contrast

image.integrated_not_pasted
image.object_fit_cover
image.shared_radius
image.gradient_overlay
image.ambient_glow
image.edge_fade
image.no_style_mismatch

background.soft_gradient
background.radial_glow
background.subtle_grain
background.masked_image
background.readability_overlay

component.unified_radius
component.unified_shadow
component.unified_border
component.bento_allowed
component.magazine_layout_allowed

button.primary_secondary_clear
button.specific_cta_text
button.hover_subtle

motion.subtle_transform_opacity
motion.duration_180_400
motion.easing_standard
motion.prefers_reduced_motion
motion.no_spin_bounce_flash

responsive.desktop_multi_column
responsive.tablet_reduced_columns
responsive.mobile_single_column
responsive.no_horizontal_scroll

a11y.alt_required
a11y.focus_required
a11y.semantic_html_required
a11y.contrast_required

content.no_lorem_ipsum
content.real_brand_tone
content.no_fake_specific_data
content.cta_specific

i18n.structure_once_translate_content
i18n.localized_seo

ban.reason_fields
ban.random_spacing
ban.too_many_colors
ban.font_mismatch
ban.hard_shadow
ban.over_glow
ban.image_module_split
ban.mobile_cramped
ban.useless_effects
```

---

## 7. BuildPlanContract v2.2

### 7.1 v2.2 相比 v2/v2.1 的关键修正

1. **`design_policy` 改为 `policy_ref + policy_projection`。**  
   避免把大段底座提示词保存进 Contract，减少 Build 上下文污染。

2. **新增 `content_manifest`。**  
   `content_keys` 只是引用，不是文案。Stage1 必须生成主语言文案，其他语言由 `i18n_generate` 任务生成。

3. **`design_manifest` 必须落成具体 token，不允许只有范围。**  
   例如不能只写 `H1: 56px-76px`，必须生成可执行值：

   ```text
   h1: clamp(40px, 6vw, 76px)
   desktop_section_padding: 120px
   mobile_section_padding: 64px
   card_padding_desktop: 32px
   ```

4. **每个 task 必须声明 `policy_slices`、`context_budget`、`acceptance_rule_ids`。**  
   Build 阶段只能读取当前任务需要的底座规则切片。

5. **冻结字段和可修复字段分离。**  
   用户确认后，`pages`、`blocks`、`tasks`、`build_order`、`design_manifest`、`policy_ref`、`policy_projection` 均冻结。Repair 只能修 render data、asset manifest、少量 content 文案质量问题。

6. **所有解释性字段禁止进入 Contract。**  
   如果需要展示说明，使用 `presentation_projection`，且标记 `never_feed_to_build`。

### 7.2 顶层字段

`BuildPlanContract v2.2` 必须包含：

```text
contract_meta
source_of_truth
policy_ref
policy_projection
site_brief
design_manifest
i18n
content_manifest
pages
blocks
tasks
build_order
permission_matrix
frozen_fields
mutable_fields
qa_gates
presentation_projection
```

### 7.3 顶层字段职责

| 字段 | 职责 | Build 可读 | Build 可写 |
| --- | --- | --- | --- |
| `contract_meta` | 契约元信息 | 是 | 否 |
| `source_of_truth` | 用户原始需求、技能快照、来源 | 是，摘要 | 否 |
| `policy_ref` | 底座策略引用 | 是 | 否 |
| `policy_projection` | 底座投影规则 | 是，切片 | 否 |
| `site_brief` | 网站定位 | 是 | 否 |
| `design_manifest` | 具体设计 token / 规则 | 是 | 否 |
| `i18n` | 国际化策略 | 是 | 否 |
| `content_manifest` | 主语言文案和 key 映射 | 是，当前 block key | 仅 i18n / content repair 可 patch 指定字段 |
| `pages` | 页面清单 | 是，当前页 | 否 |
| `blocks` | 区块清单 | 是，当前区块 | 否 |
| `tasks` | 任务图 | 是，当前任务 | 否 |
| `build_order` | 执行顺序 | 是 | 否 |
| `permission_matrix` | 阶段权限 | 是 | 否 |
| `frozen_fields` | 冻结字段 | 是 | 否 |
| `mutable_fields` | 可 patch 字段 | 是 | 否 |
| `qa_gates` | 质量门禁 | 是 | 只更新 gate status/result |
| `presentation_projection` | 前端展示摘要 | 前端可读 | 不进入 Build |

---

## 8. BuildPlanContract v2.2 示例

> 注意：这是结构示例，不是唯一值。Codex 实现时应先定义 schema，再让 PlanQueue 生成满足 schema 的 JSON。

```json
{
  "contract_meta": {
    "contract_id": "build_plan_01H_EXAMPLE",
    "type": "build_plan",
    "version": "2.2",
    "stage": "plan",
    "status": "draft",
    "primary_locale": "zh_CN",
    "created_by": "AiSitePlanQueue",
    "created_at": "2026-05-11T00:00:00Z"
  },
  "source_of_truth": {
    "user_request": "帮我做一个 AI 营销工具官网，要高级、有科技感，可以多语言",
    "normalized_request": {
      "website_type": "saas_landing_page",
      "industry": "ai_marketing",
      "target_audience": "企业市场团队和创业者",
      "business_goal": "获取试用和预约演示线索"
    },
    "selected_skills": [],
    "skill_snapshots": [],
    "design_policy_id": "premium_web_v1"
  },
  "policy_ref": {
    "policy_id": "premium_web_v1",
    "policy_version": "1.0.0",
    "policy_hash": "sha256:replace_with_registry_hash",
    "source": "AiSiteDesignPolicyRegistry"
  },
  "policy_projection": {
    "applied_rule_ids": [
      "priority.user_requirements_first",
      "priority.default_premium_when_unspecified",
      "layout.4_8_spacing",
      "layout.grid_alignment",
      "visual.clear_hierarchy",
      "image.integrated_not_pasted",
      "motion.subtle_transform_opacity",
      "responsive.no_horizontal_scroll",
      "a11y.alt_required",
      "content.no_lorem_ipsum"
    ],
    "user_overrides": [
      {
        "field": "style",
        "value": "科技感",
        "applied_to": "design_manifest.style_name"
      }
    ],
    "quality_floor": [
      "页面美观",
      "层级清晰",
      "间距统一",
      "图片与模块融合",
      "响应式良好",
      "不廉价、不杂乱、不像模板拼接"
    ],
    "banned_rule_ids": [
      "ban.reason_fields",
      "ban.random_spacing",
      "ban.too_many_colors",
      "ban.hard_shadow",
      "ban.image_module_split",
      "ban.lorem_ipsum"
    ]
  },
  "site_brief": {
    "website_type": "landing_page",
    "industry": "ai_marketing_saas",
    "target_audience": "企业市场团队、创业者和增长负责人",
    "business_goal": "引导预约演示和开始试用",
    "tone": "高级、克制、现代、可信、有轻微科技感",
    "conversion_goals": ["book_demo", "start_trial", "view_features"]
  },
  "design_manifest": {
    "style_name": "premium_dark_ai_saas",
    "visual_keywords": ["高级", "克制", "科技感", "柔和光晕", "Bento Grid", "图片融合"],
    "tokens": {
      "colors": {
        "bg": "#080B16",
        "bg_soft": "#0D1326",
        "surface": "rgba(255,255,255,0.065)",
        "surface_strong": "rgba(255,255,255,0.105)",
        "text": "#F8FAFC",
        "text_muted": "rgba(248,250,252,0.70)",
        "text_subtle": "rgba(248,250,252,0.52)",
        "primary": "#8B9CFF",
        "accent": "#58E6D9",
        "border": "rgba(255,255,255,0.13)"
      },
      "spacing": {
        "space_1": "4px",
        "space_2": "8px",
        "space_3": "12px",
        "space_4": "16px",
        "space_5": "24px",
        "space_6": "32px",
        "space_7": "48px",
        "space_8": "64px",
        "space_9": "96px",
        "space_10": "128px"
      },
      "layout": {
        "container_max_width": "1200px",
        "section_padding_desktop": "120px",
        "section_padding_tablet": "88px",
        "section_padding_mobile": "64px",
        "card_padding_desktop": "32px",
        "card_padding_mobile": "24px",
        "grid_gap_desktop": "24px",
        "grid_gap_mobile": "16px",
        "body_max_width": "680px"
      },
      "typography": {
        "font_family": "Inter, system-ui, -apple-system, BlinkMacSystemFont, 'PingFang SC', 'Microsoft YaHei', sans-serif",
        "h1_size": "clamp(40px, 6vw, 76px)",
        "h1_line_height": "1.08",
        "h1_letter_spacing": "-0.03em",
        "h2_size": "clamp(30px, 4vw, 52px)",
        "h2_line_height": "1.14",
        "body_size": "17px",
        "body_line_height": "1.72",
        "eyebrow_size": "13px",
        "eyebrow_letter_spacing": "0.12em"
      },
      "radius": {
        "sm": "12px",
        "md": "20px",
        "lg": "28px",
        "xl": "32px",
        "pill": "999px"
      },
      "shadow": {
        "soft": "0 24px 80px rgba(0,0,0,0.22)",
        "card": "0 18px 60px rgba(0,0,0,0.18)",
        "glow": "0 0 80px rgba(139,156,255,0.22)"
      },
      "motion": {
        "duration_fast": "180ms",
        "duration_base": "260ms",
        "duration_slow": "400ms",
        "easing": "cubic-bezier(0.22, 1, 0.36, 1)",
        "prefers_reduced_motion": true
      }
    },
    "background_policy": {
      "type": "dark_radial_gradient_with_subtle_grain",
      "readability_overlay_required": true,
      "allowed_effects": ["radial_glow", "soft_gradient", "subtle_grain", "vignette"]
    },
    "image_policy": {
      "fit": "object-fit: cover",
      "ratios": ["16:10", "4:3", "3:2", "1:1"],
      "integration_methods": ["shared_radius", "gradient_overlay", "ambient_glow", "edge_fade", "layered_overlap", "soft_shadow"],
      "forbid": ["isolated_pasted_image", "style_mismatch", "hard_edge_without_context"]
    },
    "component_policy": {
      "card_radius": "28px",
      "image_radius": "28px",
      "button_radius": "999px",
      "border": "1px solid rgba(255,255,255,0.13)",
      "shadow": "soft",
      "unified_icon_style": "linear_minimal"
    },
    "motion_policy": {
      "allowed": ["opacity", "translateY", "soft_shadow", "border_brightness", "background_glow"],
      "forbid": ["spin", "bounce", "flash", "large_parallax", "layout_jank"],
      "reduced_motion_required": true
    },
    "responsive_policy": {
      "desktop": "multi_column",
      "tablet": "reduced_columns",
      "mobile": "single_column",
      "forbid_horizontal_scroll": true
    },
    "a11y_policy": {
      "alt_required": true,
      "focus_state_required": true,
      "semantic_html_required": true,
      "contrast_required": true
    }
  },
  "i18n": {
    "primary_locale": "zh_CN",
    "target_locales": ["en_US"],
    "content_strategy": "structure_once_translate_content",
    "layout_strategy": "same_structure_all_locales",
    "text_storage": "content_keys",
    "seo_localized": true
  },
  "content_manifest": {
    "primary_locale": "zh_CN",
    "items": {
      "home.seo.title": "AI 营销增长平台 | 让内容、线索和转化形成闭环",
      "home.seo.description": "面向企业市场团队的 AI 营销工具，帮助团队更快完成内容生产、线索培育和增长分析。",
      "home.hero.eyebrow": "AI MARKETING OPERATING SYSTEM",
      "home.hero.title": "让营销团队从想法到转化，快一个周期",
      "home.hero.subtitle": "用 AI 把内容生成、活动编排、线索跟进和效果分析连接成一个清晰、可执行的增长工作流。",
      "home.hero.primary_cta": "预约演示",
      "home.hero.secondary_cta": "查看核心能力"
    },
    "tone_rules": ["可信", "清晰", "不夸张", "有品牌感"],
    "forbid": ["lorem ipsum", "虚假具体数据", "空泛口号"]
  },
  "pages": [
    {
      "page_id": "home",
      "route": "/",
      "page_type": "home",
      "title_key": "home.seo.title",
      "description_key": "home.seo.description",
      "blocks": [
        "home.header",
        "home.hero",
        "home.trust",
        "home.features",
        "home.showcase",
        "home.process",
        "home.final_cta",
        "home.footer"
      ]
    }
  ],
  "blocks": [
    {
      "block_id": "home.hero",
      "page_id": "home",
      "type": "hero",
      "recipe": "hero_cinematic_premium_v1",
      "intent": "建立高级第一印象",
      "content_keys": [
        "home.hero.eyebrow",
        "home.hero.title",
        "home.hero.subtitle",
        "home.hero.primary_cta",
        "home.hero.secondary_cta"
      ],
      "visual": {
        "layout": "asymmetric_hero",
        "background": "radial_gradient_with_ambient_visual",
        "image_integration": "masked_layered_visual",
        "spacing": "section_large",
        "motion": "fade_up_subtle"
      },
      "asset_requirements": [
        {
          "asset_id": "asset.home.hero.ambient",
          "kind": "ambient_background",
          "usage": "hero_background",
          "style": "soft_gradient_mesh_with_subtle_grain"
        }
      ],
      "tasks": ["task.home.hero.asset", "task.home.hero.build"]
    }
  ],
  "tasks": [
    {
      "task_id": "task.home.hero.asset",
      "kind": "asset_generate",
      "executor": "AiSiteAssetQueue",
      "page_id": "home",
      "block_id": "home.hero",
      "depends_on": [],
      "input_scope": [
        "site_brief",
        "design_manifest.tokens.colors",
        "design_manifest.image_policy",
        "blocks.home.hero.asset_requirements"
      ],
      "policy_slices": ["image.integrated_not_pasted", "background.soft_gradient", "color.low_saturation"],
      "context_budget": {
        "max_input_chars": 5000,
        "include_full_plan": false,
        "include_full_policy_prompt": false
      },
      "output_contract": "asset_manifest_item",
      "acceptance_rule_ids": ["image.integrated_not_pasted", "background.readability_overlay"],
      "acceptance": [
        "背景视觉必须符合 design_manifest 色彩",
        "不能影响 hero 文字可读性",
        "不能与模块产生割裂"
      ]
    },
    {
      "task_id": "task.home.hero.build",
      "kind": "block_build",
      "executor": "AiSiteBuildQueue",
      "page_id": "home",
      "block_id": "home.hero",
      "depends_on": ["task.home.hero.asset"],
      "input_scope": [
        "site_brief",
        "design_manifest.tokens",
        "design_manifest.component_policy",
        "design_manifest.motion_policy",
        "pages.home",
        "blocks.home.hero",
        "content_manifest.keys.home.hero.*",
        "asset.asset.home.hero.ambient"
      ],
      "policy_slices": [
        "layout.4_8_spacing",
        "typography.h1_clamp",
        "image.integrated_not_pasted",
        "motion.subtle_transform_opacity",
        "responsive.no_horizontal_scroll",
        "a11y.alt_required"
      ],
      "context_budget": {
        "max_input_chars": 9000,
        "include_full_plan": false,
        "include_full_policy_prompt": false,
        "include_neighbor_block_summary": true
      },
      "output_contract": "html_block_render_data",
      "acceptance_rule_ids": [
        "layout.4_8_spacing",
        "image.integrated_not_pasted",
        "motion.prefers_reduced_motion",
        "a11y.focus_required"
      ],
      "acceptance": [
        "必须使用 design_manifest.tokens",
        "必须响应式",
        "必须包含 alt 和 focus 状态",
        "图片必须通过遮罩、圆角、渐变或叠层与模块融合",
        "禁止输出解释性文字"
      ]
    }
  ],
  "build_order": ["task.home.hero.asset", "task.home.hero.build"],
  "permission_matrix": {
    "plan": {
      "create": ["policy_ref", "policy_projection", "site_brief", "design_manifest", "i18n", "content_manifest", "pages", "blocks", "tasks", "build_order"],
      "read": ["source_of_truth"],
      "patch": [],
      "forbidden": []
    },
    "build": {
      "create": ["asset_manifest", "html_block_render_data", "page_render_data", "theme_manifest"],
      "read": ["policy_ref", "policy_projection", "site_brief", "design_manifest", "i18n", "content_manifest", "pages", "blocks", "tasks"],
      "patch": [],
      "forbidden": ["policy_ref", "policy_projection", "site_brief", "design_manifest", "pages", "blocks", "tasks", "build_order"]
    },
    "repair": {
      "create": ["repair_patch", "qa_report"],
      "read": ["failed_gate", "mutable_fields", "local_task_context"],
      "patch": ["render_data.*", "asset_manifest.*", "content_manifest.items.*"],
      "forbidden": ["policy_ref", "policy_projection", "design_manifest", "pages", "blocks", "tasks", "build_order"]
    }
  },
  "frozen_fields": [
    "policy_ref",
    "policy_projection",
    "site_brief",
    "design_manifest",
    "i18n.primary_locale",
    "pages",
    "blocks",
    "tasks",
    "build_order"
  ],
  "mutable_fields": [
    "content_manifest.items.*",
    "asset_manifest.*",
    "render_data.*",
    "qa_gates.*.status",
    "qa_gates.*.result"
  ],
  "qa_gates": [
    {"id": "schema_valid", "status": "pending"},
    {"id": "no_reason_fields", "status": "pending"},
    {"id": "policy_ref_valid", "status": "pending"},
    {"id": "policy_projection_valid", "status": "pending"},
    {"id": "design_tokens_concrete", "status": "pending"},
    {"id": "content_manifest_keys_match_blocks", "status": "pending"},
    {"id": "all_blocks_have_tasks", "status": "pending"},
    {"id": "task_graph_acyclic", "status": "pending"},
    {"id": "build_order_covers_all_tasks", "status": "pending"},
    {"id": "image_blocks_have_integration", "status": "pending"},
    {"id": "i18n_strategy_valid", "status": "pending"}
  ],
  "presentation_projection": {
    "for_user_only": true,
    "never_feed_to_build": true,
    "title": "方案1：高级科技感 AI 营销工具官网",
    "summary_items": [
      "单页 SaaS 官网结构，聚焦预约演示和开始试用",
      "暗色高级科技风，使用柔和光晕、Bento 卡片和克制动效",
      "结构一次生成，多语言只翻译内容和 SEO，不改变布局"
    ]
  }
}
```

---

## 9. 第一阶段禁止输出内容

Stage1 生成 Contract 时，禁止出现以下字段名或语义：

```text
reason
why
rationale
thinking
analysis
explanation
chain_of_thought
设计原因
为什么这样设计
策略解释
模型思考
审美分析
详细推理
prompt日志
```

唯一允许的短意图字段：

```json
{
  "intent": "建立高级第一印象"
}
```

约束：

```text
intent 最多 24 个中文字符
intent 只说明区块作用
intent 不能解释为什么
```

如果产品前端需要展示“方案说明”，只能使用：

```json
{
  "presentation_projection": {
    "for_user_only": true,
    "never_feed_to_build": true,
    "summary_items": []
  }
}
```

并且 `AiSiteBuildPromptContextAssembler` 必须永远排除 `presentation_projection`。

---

## 10. Contract Schema 与 Linter

新增：

```text
Service/AI/Contract/BuildPlanContractSchema.php
Service/AI/Contract/BuildPlanContractValidator.php
Service/AI/Contract/BuildPlanTaskGraphValidator.php
Service/AI/Contract/BuildPlanNoReasonLinter.php
Service/AI/Contract/BuildPlanDesignPolicyLinter.php
Service/AI/Contract/BuildPlanContentManifestLinter.php
Service/AI/Contract/BuildPlanFrozenFieldValidator.php
```

### 10.1 `BuildPlanContractSchema`

职责：返回 schema 规则和必填字段。

必须包含：

```php
public function version(): string; // 2.2
public function requiredTopLevelFields(): array;
public function allowedTaskKinds(): array;
public function allowedExecutors(): array;
public function forbiddenFieldNames(): array;
public function requiredDesignTokenGroups(): array;
public function requiredPolicyFields(): array;
public function requiredBlockFields(): array;
public function requiredTaskFields(): array;
```

### 10.2 `BuildPlanContractValidator`

检查：

```text
顶层字段完整
字段类型正确
contract_meta.version = 2.2
policy_ref.policy_id 存在且为 premium_web_v1 或注册策略
policy_projection.applied_rule_ids 非空
site_brief 完整
design_manifest.tokens 完整
content_manifest.items 覆盖所有 content_keys
pages / blocks / tasks 引用完整
permission_matrix 合法
frozen_fields / mutable_fields 不冲突
```

### 10.3 `BuildPlanTaskGraphValidator`

检查：

```text
每个 task_id 唯一
每个 depends_on 指向已存在 task
task graph 无环
build_order 覆盖全部 task
build_order 满足依赖顺序
每个 block 至少有一个 task
每个 page.blocks 指向存在 block
每个 block.page_id 指向存在 page
```

伪代码：

```php
foreach ($tasks as $task) {
    assertUnique($task['task_id']);
    foreach ($task['depends_on'] as $dep) {
        assertTaskExists($dep);
    }
}

assertAcyclic($taskGraph);
assertBuildOrderCoversAllTasks($buildOrder, $tasks);
assertBuildOrderRespectsDependencies($buildOrder, $taskGraph);
```

### 10.4 `BuildPlanNoReasonLinter`

递归扫描所有 key：

```php
private const FORBIDDEN_KEYS = [
    'reason', 'why', 'rationale', 'thinking', 'analysis', 'explanation',
    'chain_of_thought', '设计原因', '为什么', '策略解释', '模型思考', '审美分析', '详细推理', 'prompt日志'
];
```

发现即 hard error。

### 10.5 `BuildPlanDesignPolicyLinter`

检查：

```text
source_of_truth.design_policy_id 与 policy_ref.policy_id 一致
policy_ref.policy_version 存在
policy_projection.applied_rule_ids 均在 Registry 中存在
用户明确风格没有被默认底座覆盖
design_manifest 已从底座投影出 tokens / policy
图片相关 block 必须有 visual.image_integration
每个 task.policy_slices 均为合法 rule id
每个 task.acceptance_rule_ids 均为合法 rule id
禁止模式没有进入 pages / blocks / tasks
```

### 10.6 `BuildPlanContentManifestLinter`

检查：

```text
pages.*.title_key 在 content_manifest.items 中存在
pages.*.description_key 在 content_manifest.items 中存在
blocks.*.content_keys 全部存在
content_manifest.items 不含 lorem ipsum
不含明显占位文案
不含过度具体的虚假数据
CTA 文案不能全部是“了解更多”
primary_locale 与 i18n.primary_locale 一致
```

### 10.7 `BuildPlanFrozenFieldValidator`

检查：

```text
Build 阶段不能 patch frozen_fields
Repair 阶段不能 patch pages / blocks / tasks / design_manifest / policy_ref / policy_projection
mutable_fields 不得包含 frozen_fields
permission_matrix.forbidden 与 patch/create 不冲突
```

---

## 11. 队列职责重排

### 11.1 `AiSitePlanQueue`

保留类名，减少前端改动。

重构后职责：

```text
读取用户需求、技能快照、session facts
读取 premium_web_v1 策略
调用 AI 生成 BuildPlanContract v2.2
执行 contract validators / linters
必要时执行一次 schema repair
保存 build_plan_v2 artifact
保存 plan_projection artifact
推送 SSE: plan_ready
```

禁止职责：

```text
不生成代码
不执行 Build
不生成 Stage2 task plan
不把 presentation_projection 当作 Build 输入
```

建议伪代码：

```php
public function execute(Queue $queue): void
{
    $session = $this->sessionService->loadFromQueue($queue);
    $facts = $this->intakeNormalizer->normalize($session);
    $policy = $this->designPolicyRegistry->get('premium_web_v1');

    $prompt = $this->planPromptBuilder->build([
        'facts' => $facts,
        'policy' => $policy,
        'schema_version' => '2.2',
    ]);

    $contract = $this->aiClient->json($prompt);

    $report = $this->buildPlanValidator->validateAll($contract);
    if (!$report->passed()) {
        $contract = $this->planRepairService->repairSchemaOnly($contract, $report);
        $report = $this->buildPlanValidator->validateAll($contract);
    }

    if (!$report->passed()) {
        throw new BuildPlanContractException($report);
    }

    $this->artifactService->save($session->id, 'build_plan_v2', $contract);
    $this->artifactService->save($session->id, 'plan_projection', $this->projectionService->forUser($contract));
    $this->eventService->emit($session->id, 'plan_ready', [...]);
}
```

### 11.2 `AiSiteTaskPlanQueue`

保留但降级。

允许触发条件：

```text
legacy_session = true
manual_deep_refine = true
debug_mode = true
large_site = pages_count > 10
```

否则 hard reject：

```php
if (!$this->legacyGuard->canRunTaskPlan($session)) {
    throw new RuntimeException('AiSiteTaskPlanQueue is legacy-only in BuildPlanContract v2.2 flow.');
}
```

### 11.3 `BuildPlanTaskScheduler`

新增：

```text
Service/AiSiteBuildPlanTaskScheduler.php
```

职责：

```text
读取已确认 build_plan_v2
按 build_order 创建 queue rows
asset_generate 进入 AiSiteAssetQueue
block_build / page_assemble / seo_generate / i18n_generate 进入 AiSiteBuildQueue 或对应队列
记录 task_id 与 queue_id 映射
设置 session active_operation
推送 scheduling_tasks / building_* 事件
```

### 11.4 `AiSiteBuildQueue`

重构后直接消费：

```text
build_plan_v2.tasks
```

`validate()` 检查：

```text
build_plan_v2 存在
build_plan_v2 已确认
task_id 存在
task 依赖已完成
task kind 可执行
output_contract 合法
Build 不允许修改 frozen_fields
```

`execute()` 做：

```text
读取当前 task
通过 AiSiteBuildPromptContextAssembler 组装最小上下文
调用 AI / renderer 生成 output_contract
保存 task_result artifact
更新 task status
触发下游依赖任务或等待 scheduler 调度
```

禁止：

```text
不重新规划 pages
不新增 blocks
不修改 design_manifest
不读取完整底座 prompt
不读取 presentation_projection
不输出解释性内容
```

### 11.5 `AiSiteAssetQueue`

继续保留。

职责：

```text
消费 asset_generate / asset_select / asset_optimize task
读取当前 asset requirement
读取 image_policy / background_policy slice
输出 asset_manifest_item
保存到 asset_manifest
```

### 11.6 `AiSiteQualityGateService`

继续保留，但检查对象改为：

```text
BuildPlanContract v2.2
asset_manifest
html_block_render_data
page_render_data
theme_manifest
content_manifest
```

### 11.7 Controller / SSE 边界

禁止：

```text
Controller 里直接 queue:run
SSE 里启动 PHP worker
浏览器请求生命周期里创建长进程
把 php bin/w queue:run --id=<queue_id> 当生产路径
```

允许：

```text
Controller 创建 plan/build 队列
Controller 确认 build_plan_v2
SSE 读取 queue snapshot / session events / artifact projection
SSE 推送状态和日志
```

---

## 12. Artifact 存储建议

复用：

```text
Model/AiSiteAgentSessionArtifact.php
Service/AiSiteAgentSessionArtifactService.php
```

建议 artifact type：

```text
build_plan_v2
plan_projection
content_manifest
asset_manifest
task_result
html_block_render_data
page_render_data
theme_manifest
qa_report
repair_patch
publish_manifest
legacy_execution_blueprint
legacy_virtual_theme_plan
```

写入规则：

| artifact type | 创建阶段 | 是否给 Build 读取 | 说明 |
| --- | --- | --- | --- |
| `build_plan_v2` | Plan | 是，按 input_scope 切片 | 唯一主计划 |
| `plan_projection` | Plan | 否 | 前端展示“方案1” |
| `content_manifest` | Plan / i18n | 是，按 content_keys 切片 | 主语言与翻译文案 |
| `asset_manifest` | Asset | 是 | 资源产物 |
| `task_result` | Build | 是，依赖任务可读 | 任务执行产物 |
| `html_block_render_data` | Build | 是 | 区块 HTML/CSS/JS 或 render JSON |
| `page_render_data` | Assemble | 是 | 页面组装产物 |
| `qa_report` | QA | 是，Repair 可读 | 质量报告 |
| `repair_patch` | Repair | 是 | 修复补丁 |
| `plan_projection` | Plan | 前端读 | 永不喂给 Build |

---

## 13. Prompt 分层策略

### 13.1 Stage1 Plan Prompt

Stage1 使用：

```text
System contract prompt
+ premium_web_v1 full/compact policy summary
+ user request
+ selected skills snapshot
+ BuildPlanContract v2.2 schema instruction
```

输出：

```text
JSON only
BuildPlanContract v2.2 only
```

不得输出：

```text
Markdown
解释
设计原因
代码
Stage2 task plan
```

#### Stage1 系统提示词核心

```text
你是 AI 建站规划器。你的任务不是写文章，不是解释设计原因，而是根据用户需求生成一个可执行的 BuildPlanContract v2.2 JSON。

你必须输出且只能输出 JSON。
禁止输出 Markdown。
禁止输出解释。
禁止输出每个区块的设计原因。
禁止输出 reason、why、rationale、thinking、analysis、explanation、设计原因、为什么 等字段。

你的输出必须同时满足两个目标：
1. 作为用户看到的“方案1”的数据源。
2. 作为后台 Build 队列可直接消费的任务图。

用户明确要求优先。
用户没有说明的地方，使用 policy_ref.policy_id = premium_web_v1。
高级感来自：统一间距、视觉层级、留白、比例、克制配色、精致字体、图片融合、柔和动效、响应式和可访问性。

必须生成以下顶层字段：
contract_meta, source_of_truth, policy_ref, policy_projection, site_brief, design_manifest, i18n, content_manifest, pages, blocks, tasks, build_order, permission_matrix, frozen_fields, mutable_fields, qa_gates, presentation_projection。

强制规则：
1. 每个 page 必须有 blocks。
2. 每个 block 必须至少映射一个 task。
3. 每个 task 必须有 task_id、kind、executor、depends_on、input_scope、policy_slices、context_budget、output_contract、acceptance_rule_ids、acceptance。
4. tasks 必须组成无环任务图。
5. build_order 必须包含全部 task_id，并满足依赖顺序。
6. design_manifest 必须包含 concrete tokens，不能只有范围描述。
7. 所有用户可见文案必须进入 content_manifest.items，并由 content_keys 引用。
8. 图片相关 block 必须声明 visual.image_integration。
9. i18n 必须声明 primary_locale、target_locales、content_strategy、layout_strategy、text_storage、seo_localized。
10. Build 阶段不得修改 frozen_fields。
11. QA 和 Repair 只能修改 mutable_fields。
12. 如果用户需求不足，基于合理假设继续生成，不要反复追问。
```

### 13.2 Build Prompt

Build 每次只执行一个 task。

输入：

```text
当前 task
当前 block
当前 page 摘要
当前 content keys 对应文案
当前 task 依赖产物
当前 task policy_slices
当前 output_contract schema
相邻 block 极简摘要，可选
```

禁止输入：

```text
完整底座提示词
完整方案解释
presentation_projection
Stage2 历史
无关页面全部内容
```

#### Build 系统提示词核心

```text
你是网站区块构建器。
你只能根据当前 task 生成 output_contract 指定的产物。
你不能修改 plan、policy_ref、policy_projection、pages、blocks、tasks、design_manifest。
你不能重新规划网站。
你不能输出解释。
你只能输出 JSON。
你不能读取完整底座提示词，只能读取当前 task 所需的 policy_slices。

必须遵守：
- 使用 design_manifest.tokens
- 遵守 task.acceptance_rule_ids
- 遵守 task.acceptance
- 使用 4/8px spacing
- 图片必须融合
- 响应式
- 可访问性
- prefers-reduced-motion
- 禁止硬编码非当前 locale 文案
- 禁止输出 reason / explanation
```

### 13.3 Asset Prompt

```text
你是网站视觉资源生成/选择器。
你只能根据当前 asset task 输出 asset_manifest_item JSON。
你不能生成页面结构。
你不能修改 design_manifest、blocks、tasks。
资源必须符合 design_manifest 色彩、image_policy 和 background_policy。
资源不能影响文字可读性，不能与模块产生割裂。
```

### 13.4 I18n Prompt

```text
你是网站内容本地化器。
结构、布局、block、task 不允许改变。
你只能把 content_manifest.items 从 primary_locale 翻译为 target_locale，并生成 localized SEO 文案。
不得扩写成另一套网站。
不得改变 CTA 意图。
不得改变 content_key。
输出必须是 content_manifest locale patch JSON。
```

### 13.5 Repair Prompt

```text
你是契约修复器。
你只能根据 failed gate 和 mutable_fields 生成 repair_patch JSON。
你不能重新规划。
你不能修改 frozen_fields。
你不能新增 page/block/task。
你不能修改 design_manifest。
你只能修复局部 render_data、asset_manifest 或允许修复的 content_manifest 文案问题。
```

---

## 14. `AiSiteBuildPromptContextAssembler`

新增：

```text
Service/AiSiteBuildPromptContextAssembler.php
```

职责：

```text
根据 task.input_scope 精确裁剪上下文
根据 task.policy_slices 从 Registry 中取最小规则文本
根据 task.context_budget 控制输入大小
排除 presentation_projection
排除 full_policy_prompt
排除无关 blocks/pages/tasks
只给 Build 当前任务所需内容
```

必须提供：

```php
public function assembleForTask(array $buildPlan, string $taskId): array;
public function resolveInputScope(array $buildPlan, array $task): array;
public function resolvePolicySlices(array $policy, array $ruleIds): array;
public function enforceBudget(array $context, array $budget): array;
```

硬规则：

```text
include_full_plan = false 时，不得输出完整 build_plan_v2
include_full_policy_prompt = false 时，不得输出 full_policy_prompt
presentation_projection 永远不得输出
source_of_truth.user_request 只允许摘要，不允许所有历史消息
```

---

## 15. Block Recipe 策略

不要让 AI 每次从零设计区块。应维护固定 recipe，让 AI 只选择和填参。

复用并强化：

```text
Service/AI/Contract/BlockRecipeRegistry.php
```

### 15.1 推荐 recipe

```text
header_glass_premium_v1
hero_cinematic_premium_v1
trust_minimal_logo_strip_v1
features_bento_grid_v1
showcase_integrated_image_v1
benefits_editorial_cards_v1
process_timeline_soft_v1
testimonial_editorial_v1
pricing_premium_cards_v1
faq_accordion_minimal_v1
final_cta_ambient_panel_v1
footer_low_contrast_v1
```

### 15.2 recipe 示例

```json
{
  "recipe": "hero_cinematic_premium_v1",
  "allowed_block_type": "hero",
  "required_tokens": ["colors", "spacing", "layout", "typography", "radius", "motion"],
  "default_layout": "asymmetric_hero",
  "image_integration_required": true,
  "responsive_required": true,
  "a11y_required": true,
  "recommended_policy_slices": [
    "layout.4_8_spacing",
    "typography.h1_clamp",
    "image.integrated_not_pasted",
    "background.readability_overlay",
    "motion.subtle_transform_opacity"
  ],
  "banned": ["hard_shadow", "random_spacing", "isolated_image", "too_many_ctas"]
}
```

### 15.3 Linter 检查

```text
block.recipe 必须存在于 registry
block.type 必须匹配 recipe.allowed_block_type
recipe.required_tokens 必须在 design_manifest.tokens 中存在
image_integration_required = true 时，block.visual.image_integration 必填
recommended_policy_slices 应进入 task.policy_slices
```

---

## 16. 国际化策略

AI 建站不应为每个语言重新生成一套网站结构。

正确方式：

```text
结构生成一次
默认语言生成内容
其他语言只做内容翻译和 SEO 本地化
布局不变
组件不变
任务图不变
```

Contract：

```json
{
  "i18n": {
    "primary_locale": "zh_CN",
    "target_locales": ["en_US", "ja_JP"],
    "content_strategy": "structure_once_translate_content",
    "layout_strategy": "same_structure_all_locales",
    "text_storage": "content_keys",
    "seo_localized": true
  }
}
```

禁止：

```text
中文版一个布局
英文版另一个布局
日文版又变一个布局
```

允许：

```text
不同语言文案长度差异导致微调 typography overflow / line clamp / CTA width
但不得改变区块结构和任务图
```

---

## 17. 任务类型固定表

AI 不能自由发明无限 task kind。

允许 task kind：

```text
theme_prepare
asset_generate
asset_select
asset_optimize
block_build
block_patch
page_assemble
i18n_generate
seo_generate
qa_check
repair_patch
publish_prepare
```

允许 executor：

```text
AiSitePlanQueue
AiSiteAssetQueue
AiSiteBuildQueue
AiSiteQualityGateService
ContractRepairExecutor
AiSitePublishService
```

每个 task 必填：

```text
task_id
kind
executor
page_id，可选但 block/page 任务必填
block_id，可选但 block 任务必填
depends_on
input_scope
policy_slices
context_budget
output_contract
acceptance_rule_ids
acceptance
```

---

## 18. Output Contract 类型

建议固定 output_contract：

```text
asset_manifest_item
html_block_render_data
page_render_data
i18n_content_patch
seo_manifest_item
qa_report
repair_patch
publish_manifest
```

### 18.1 `html_block_render_data`

建议结构：

```json
{
  "task_id": "task.home.hero.build",
  "page_id": "home",
  "block_id": "home.hero",
  "html": "<section ...>...</section>",
  "css": ":root {...}",
  "js": "",
  "used_content_keys": ["home.hero.title"],
  "used_asset_ids": ["asset.home.hero.ambient"],
  "a11y": {
    "alt_present": true,
    "focus_states_present": true,
    "semantic_html": true
  },
  "responsive": {
    "desktop": true,
    "tablet": true,
    "mobile": true,
    "no_horizontal_scroll": true
  },
  "motion": {
    "uses_transform_opacity": true,
    "prefers_reduced_motion": true
  }
}
```

### 18.2 `asset_manifest_item`

```json
{
  "asset_id": "asset.home.hero.ambient",
  "kind": "ambient_background",
  "url": "...",
  "alt_key": "home.hero.asset.ambient.alt",
  "usage": "hero_background",
  "dominant_colors": ["#080B16", "#8B9CFF"],
  "integration": {
    "overlay_required": true,
    "radius": "28px",
    "edge_fade": true
  }
}
```

---

## 19. 前端方案1展示

前端不要让 AI 另外生成一篇“方案说明”。

前端展示必须由：

```text
BuildPlanContract v2.2 -> AiSiteBuildPlanProjectionService -> plan_projection
```

生成。

新增/改造：

```text
Service/AiSiteBuildPlanProjectionService.php
```

展示模块：

```text
方案标题
网站定位
视觉方向
页面清单
区块清单
多语言策略
生成任务概览
预计产物
确认并开始生成
```

展示字段来源：

| UI 内容 | 数据来源 |
| --- | --- |
| 方案标题 | `presentation_projection.title` |
| 网站定位 | `site_brief` |
| 视觉方向 | `design_manifest.style_name`, `design_manifest.visual_keywords`, `policy_projection.quality_floor` |
| 页面清单 | `pages` |
| 首页区块 | `blocks` |
| 多语言策略 | `i18n` |
| 生成任务 | `tasks.kind`, `build_order` |
| 审美底座 | `policy_ref.policy_id`, `policy_projection.applied_rule_ids` |

前端按钮：

```text
确认方案1并开始生成
```

可选但不默认展示：

```text
深度细化方案
```

该按钮触发 legacy / manual deep refine，不能进入默认流程。

---

## 20. 质量门禁

### 20.1 Plan 阶段 gates

```text
schema_valid
no_reason_fields
policy_ref_valid
policy_projection_valid
design_tokens_concrete
design_manifest_complete
content_manifest_keys_match_blocks
content_no_lorem_ipsum
pages_blocks_reference_valid
all_blocks_have_tasks
task_graph_acyclic
build_order_covers_all_tasks
build_order_respects_dependencies
image_blocks_have_integration
i18n_strategy_valid
permission_matrix_valid
frozen_mutable_no_conflict
```

### 20.2 Build 阶段 gates

```text
output_contract_valid
uses_design_tokens
no_frozen_field_patch
html_semantic_valid
css_no_random_spacing
a11y_alt_focus_valid
responsive_no_horizontal_scroll
motion_reduced_motion_valid
image_integration_valid
no_lorem_ipsum
no_reason_output
```

### 20.3 Repair 阶段 gates

```text
repair_patch_schema_valid
repair_patch_mutable_only
repair_does_not_replan
failed_gate_resolved
no_new_reason_fields
```

---

## 21. 服务新增 / 改造清单

### 21.1 P0 必做服务

```text
Service/AiSiteDesignPolicyRegistry.php
Service/AiSiteDesignPolicyPromptBuilder.php
Service/AI/Contract/BuildPlanContractSchema.php
Service/AI/Contract/BuildPlanContractValidator.php
Service/AI/Contract/BuildPlanTaskGraphValidator.php
Service/AI/Contract/BuildPlanNoReasonLinter.php
Service/AI/Contract/BuildPlanDesignPolicyLinter.php
Service/AI/Contract/BuildPlanContentManifestLinter.php
```

### 21.2 P1 必做服务

```text
Service/AiSiteBuildPlanService.php
Service/AiSiteBuildPlanProjectionService.php
Service/AiSiteBuildPlanTaskScheduler.php
Service/AiSiteBuildPromptContextAssembler.php
```

### 21.3 复用/改造现有服务

```text
AiSiteExecutionBlueprintService.php       -> 作为 legacy adapter 或 v2 plan 辅助，不再默认输出主方案
AiSiteVirtualThemePlanService.php         -> legacy only
AiSiteBuildTaskService.php                -> 消费 v2 task
AiSiteHtmlBlocksBuildService.php          -> 输出 html_block_render_data
AiSiteQualityGateService.php              -> 增加 v2 gates
AiSiteAssetManifestService.php            -> 支持 asset_manifest_item
AiSiteAgentSessionArtifactService.php     -> 存储 build_plan_v2 / task_result
AiSiteTaskPlanSseService.php              -> 改为更通用 plan/build 状态推送，或保留 legacy 名称但兼容 v2
```

---

## 22. 实施分期

### P0：Contract 和底座策略落地

目标：不改 UI，不改 BuildQueue，先让 v2.2 Contract 能生成并校验。

任务：

```text
1. 新增 AiSiteDesignPolicyRegistry
2. 新增 AiSiteDesignPolicyPromptBuilder
3. 新增 BuildPlanContractSchema
4. 新增 BuildPlanContractValidator
5. 新增 BuildPlanTaskGraphValidator
6. 新增 BuildPlanNoReasonLinter
7. 新增 BuildPlanDesignPolicyLinter
8. 新增 BuildPlanContentManifestLinter
9. 为以上服务写单测
```

验收：

```text
Contract 缺顶层字段 -> hard error
出现 reason/why/rationale -> hard error
task graph 有环 -> hard error
content_keys 找不到文案 -> hard error
policy_slices 不存在 -> hard error
```

### P1：PlanQueue 输出 BuildPlanContract v2.2

目标：`AiSitePlanQueue` 默认输出 `build_plan_v2` artifact。

任务：

```text
1. 改 AiSitePlanQueue prompt 构造
2. 写入 build_plan_v2 artifact
3. 写入 plan_projection artifact
4. PlanQueue 执行 linter
5. linter 失败时只做 schema-only repair，不做 Stage2
6. SSE 推送 plan_ready
```

验收：

```text
用户一句话需求 -> 生成 build_plan_v2
前端可读取 plan_projection
不出现 Stage2 默认任务
```

### P2：前端只确认一次

目标：保留“方案1”展示，去掉默认“生成任务方案”步骤。

任务：

```text
1. 方案页读取 plan_projection
2. 按钮改为“确认方案1并开始生成”
3. 确认后标记 build_plan_v2.status = confirmed
4. 触发 BuildPlanTaskScheduler
5. 隐藏 Stage2 默认 UI
6. Debug / legacy 才显示深度细化入口
```

验收：

```text
用户只确认一次
无默认 Stage2 pending/ready/confirmed
确认后进入 scheduling_tasks/building 状态
```

### P3：BuildQueue 消费任务图

目标：`AiSiteBuildQueue` 直接消费 `build_plan_v2.tasks`。

任务：

```text
1. 新增 AiSiteBuildPlanTaskScheduler
2. 新增 AiSiteBuildPromptContextAssembler
3. BuildQueue validate 读取 build_plan_v2
4. BuildQueue execute 当前 task
5. 保存 task_result / html_block_render_data
6. 依赖任务完成后继续调度
```

验收：

```text
BuildQueue 不读取 Stage2
BuildQueue 不读取完整底座 prompt
BuildQueue 不修改 frozen_fields
BuildQueue 每次只执行当前 task
```

### P4：QA / Repair v2

目标：QA 检查 v2 产物，Repair 只 patch mutable fields。

任务：

```text
1. AiSiteQualityGateService 增加 v2 gates
2. ContractPatchValidator 支持 v2.2 frozen/mutable
3. Repair prompt 改成 mutable-only
4. 保存 qa_report / repair_patch
5. Repair 后重新跑 failed gate
```

验收：

```text
Repair 修改 design_manifest -> hard error
Repair 新增 block/task -> hard error
Repair 只修 render_data / asset_manifest / 允许 content 文案
```

### P5：Legacy 兼容和回归

目标：旧会话不崩，新会话默认 v2.2。

任务：

```text
1. AiSiteTaskPlanQueue 增加 legacy guard
2. LegacyContractAdapter 支持 execution_blueprint / virtual_theme_plan -> build_plan_v2 projection 或继续旧路径
3. 老会话标记 legacy_session
4. Debug 模式允许手动 deep refine
5. 回归旧流程测试
```

验收：

```text
新会话默认 v2.2
旧会话仍能继续
Stage2 不再默认出现
Debug 手动入口可用
```

---

## 23. 测试计划

### 23.1 单测

```text
BuildPlanContractSchemaTest
BuildPlanContractValidatorTest
BuildPlanTaskGraphValidatorTest
BuildPlanNoReasonLinterTest
BuildPlanDesignPolicyLinterTest
BuildPlanContentManifestLinterTest
AiSiteDesignPolicyRegistryTest
AiSiteBuildPromptContextAssemblerTest
AiSiteBuildPlanProjectionServiceTest
AiSiteBuildPlanTaskSchedulerTest
```

### 23.2 集成测试

```text
PlanQueueGeneratesBuildPlanV2Test
PlanQueueRejectsReasonFieldsTest
BuildQueueConsumesBuildPlanTasksTest
BuildQueueDoesNotReadFullPolicyPromptTest
RepairCannotPatchFrozenFieldsTest
LegacyTaskPlanQueueGuardTest
I18nStructureOnceTranslateContentTest
```

### 23.3 人工验收场景

```text
1. 用户只输入：“做一个 AI 工具官网”
   期望：默认 premium_web_v1，高级、克制、完整任务图。

2. 用户输入：“做一个可爱风宠物店网站”
   期望：用户风格优先，但仍保持统一间距、响应式、图片融合。

3. 用户输入：“要很多颜色、很多动画、很炫”
   期望：保留活泼意图，但底座修正廉价、刺眼、过度动效。

4. 用户选择 zh_CN + en_US
   期望：结构一次生成，en_US 只翻译 content_manifest 和 SEO。

5. 人为注入 reason 字段
   期望：Plan Linter hard error。

6. 人为制造 task graph cycle
   期望：TaskGraphValidator hard error。

7. Repair 尝试修改 design_manifest
   期望：ContractPatchValidator hard error。
```

---

## 24. Codex 开发指令

把下面这段直接给 Codex，要求它按顺序实施：

```text
你正在重构 app/code/GuoLaiRen/PageBuilder 的 AI 建站工作台。

目标：把默认流程从 Plan -> TaskPlan -> Build 改为 Plan(BuildPlanContract v2.2) -> 用户确认一次 -> BuildQueue 直接执行 build_plan_v2.tasks。

硬要求：
1. 不删除 AiSiteTaskPlanQueue，先降级为 legacy/manual/debug only。
2. 不改 Controller/SSE 为直接执行队列，Controller/SSE 只创建队列、确认计划、读取状态。
3. 新增 premium_web_v1 设计策略注册表，但不要把完整底座 prompt 塞进每个 Build 任务。
4. Stage1 输出 BuildPlanContract v2.2，必须包含 policy_ref、policy_projection、design_manifest、content_manifest、pages、blocks、tasks、build_order、qa_gates。
5. Stage1 禁止输出 reason/why/rationale/thinking/analysis/explanation 等字段。
6. BuildQueue 每次只执行一个 task，输入必须由 AiSiteBuildPromptContextAssembler 按 task.input_scope 和 task.policy_slices 裁剪。
7. BuildQueue 不允许修改 frozen_fields。
8. Repair 只能 patch mutable_fields。
9. 前端方案展示必须来自 plan_projection，不要让 AI 生成第二份展示文案。
10. 先完成 P0/P1 单测，再改 BuildQueue。

第一步只做：
- AiSiteDesignPolicyRegistry
- AiSiteDesignPolicyPromptBuilder
- BuildPlanContractSchema
- BuildPlanContractValidator
- BuildPlanTaskGraphValidator
- BuildPlanNoReasonLinter
- BuildPlanDesignPolicyLinter
- BuildPlanContentManifestLinter
- 对应单测

完成第一步后，提交 diff，不要继续大范围改 UI。
```

---

## 25. 最终验收标准

### 25.1 契约验收

```text
Stage1 输出 BuildPlanContract v2.2
输出不含 reason 类字段
policy_ref 指向 premium_web_v1
policy_projection 包含应用规则
用户明确风格优先于默认底座
design_manifest tokens 是具体值，不是只有范围
content_manifest 覆盖所有 content_keys
每个 page 有 blocks
每个 block 有 task
每个 task 有 policy_slices / context_budget / acceptance_rule_ids
task graph 无环
build_order 覆盖全部 task
i18n 使用 structure_once_translate_content
图片区块有 image_integration
frozen_fields / mutable_fields 不冲突
```

### 25.2 流程验收

```text
用户只确认一次方案1
默认不进入 AiSiteTaskPlanQueue
BuildPlanTaskScheduler 能创建后台任务
AiSiteAssetQueue / AiSiteBuildQueue 能按 build_order 执行
BuildQueue 不读取完整底座 prompt
BuildQueue 不读取 presentation_projection
QA 能检查 v2 产物
Repair 不能修改 frozen_fields
旧会话仍可 legacy 兼容
```

### 25.3 审美验收

```text
页面有清晰视觉层级
使用 4/8px 间距体系
section / card / gap / button 间距稳定
字体层级明确
颜色克制，不超过 2-3 个主色
图片与模块融合，不像贴图
背景有层次但不影响可读性
卡片、按钮、图片圆角统一
动效克制，使用 transform / opacity
支持 prefers-reduced-motion
移动端不拥挤，无横向滚动
无 lorem ipsum
文案自然、有品牌感
```

---

## 26. 不做事项

本轮不要做：

```text
不重写整个 PageBuilder
不删除 legacy 数据
不把所有服务合并成一个巨型服务
不把 UI 彻底重做
不把完整 premium_web_v1 prompt 存进每个 task
不让 AI 自由发明 task kind
不让 AI 自由发明 block recipe
不在浏览器请求里跑长任务
不把手动 queue:run 当生产验证路径
```

---

## 27. 最重要的架构原则

```text
规划只做一次。
解释不进入构建。
方案就是任务图。
底座提示词工程化为 policy，不作为长上下文反复注入。
Build 只执行当前 task，不重新理解整站。
QA 只校验，不重新规划。
Repair 只 patch mutable_fields。
前端展示来自 Contract projection，不再生成第二套方案。
```

最终目标：

```text
把“方案1”从一篇长文，变成一个可执行 BuildPlanContract。
```

这样既保留用户能理解的“方案形式”，又避免 Stage2 二次理解和 Build 三次理解导致的漂移。

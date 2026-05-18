# PageBuilder AI 建站｜13 项门禁 × Prompt 契约 对照表

> 适用范围：阶段二「强契约并发构建」AI 内容块 / 共享 header / footer 生成。
> 文档目的：把 `AiSiteQualityGateService::QUALITY_ITEM_SPECS` 中的 13 项门禁规则
> **反向编码** 为 prompt 自检清单，确保 AI 在生成前按相同规则做内置自检，避免出现
> 「prompt 鼓励 X，但门禁要求非 X」的契约矛盾，从根本上消灭「补丁式修复」。

## 0. 总览

| # | 门禁 ID | page_report_field | 判定来源 | 反向编码位置 |
|---|---------|-------------------|---------|--------------|
| 1 | build_tasks_done | — | `inspectBuildCompletionGate` | 不在 prompt（队列调度） |
| 2 | task_coverage | — | `buildTaskCoverageReport` | 不在 prompt（队列调度） |
| 3 | required_pages_render | — | 渲染成功率 | 不在 prompt（运行时） |
| 4 | shared_blocks_ready | shared_blocks | `inspectRenderedPage.shared_blocks_ready` | `renderSharedRegionVisualContract` |
| 5 | source_truth_coverage | — | `SourceTruthCoverageLinter` | `renderSectionVisualContract.must_include_facts` |
| 6 | render_data_quality | — | `RenderDataQualityLinter` | `renderSectionVisualContract.gate#render_data_quality` |
| 7 | content_quality | bad_matches | `inspectRenderedPage.content_clean` | `renderSectionVisualContract.gate#content_quality` |
| 8 | stage1_content_visible | stage1_hits | `matchStageOneContent` | `renderSectionVisualContract.gate#stage1_content_visible` + 必含事实 |
| 9 | theme_visible | theme_hits | `matchThemeHits` | `renderSectionVisualContract.gate#theme_visible` + `REQUIRED_PALETTE_ROLE_MAP` |
| 10 | visual_assets_safe | visuals | `inspectRenderedPage.visuals_safe` | `renderSectionVisualContract.gate#visual_assets_safe` + `verified_asset_src_allowlist` |
| 11 | visual_depth | visual_depth_signals | `matchVisualDepthSignals` | `renderSectionVisualContract.gate#visual_depth` + V3 输出契约 §8 |
| 12 | language_consistency | language_violations | `matchLanguageViolations` | `renderSectionVisualContract.content_locale (HARD)` + `Stage3LocaleExecutionPromptAddon` |
| 13 | responsive_support | responsive_signals | `matchResponsiveSignals` | `renderSectionVisualContract.gate#responsive_support` + V3 输出契约 §9 |

> 关键改动 v3：
> - 不再使用「禁止 @media / 禁止 linear-gradient / 禁止 clamp」等与门禁 11/13 直接打架的条款；
> - CSS 预算上调到 html_content ≤ 2400 / css_extra ≤ 2400 / css_responsive ≤ 900；
> - 取消硬编码 SAFE_CSS（#111827 / #f59e0b 等模板色），改为 `REQUIRED_PALETTE_ROLE_MAP` 从 scope themePalette 实时取色；
> - `ensureAiPayloadValid` 抛 `AiSiteComponentContractException` 并携带 findings，`buildStrictComponentRecoveryPrompt` 把 findings 中文回灌给 AI 做定点修复。

---

## 1. visual_depth — 视觉层次门禁

**门禁判定**（`AiSiteQualityGateService::matchVisualDepthSignals`）：

```php
patterns = [
    'gradient' => '/linear-gradient|radial-gradient/iu',
    'shadow'   => '/box-shadow|drop-shadow|filter:\s*drop-shadow/iu',
    'visual'   => '/<img\b|data-pb-ai-image-role|background-image|url\(|vt-visual|css-only|pseudo-element/iu',
    'layout'   => '/display\s*:\s*(?:grid|flex)|grid-template-columns/iu',
    'motion'   => '/transition\s*:|animation\s*:|transform\s*:/iu',
    'surface'  => '/border-radius|backdrop-filter|color-mix\(/iu',
];
ok = count(signals) >= 3
```

**Prompt 反向编码**（`AiSiteVisualBlockContractRenderer::renderSectionVisualContract`）：

```
[gate#visual_depth] 视觉层次门禁 — 需在 css_extra / html_content 中至少命中 3 条，建议同时命中 4 条以确保稳定通过：
  1) gradient：至少出现一次 linear-gradient(...) 或 radial-gradient(...)
  2) shadow：box-shadow 至少出现一次
  3) visual：<img data-pb-ai-image-role=...> 或 url(...) 或 vt-visual 或 ::before/::after
  4) layout：display:grid 或 display:flex 至少一次
  5) motion：transition: 至少一次（hover/focus/active）
  6) surface：border-radius 至少一次；可选 backdrop-filter 或 color-mix(...)
```

---

## 2. responsive_support — 响应式门禁

**门禁判定**（`AiSiteQualityGateService::matchResponsiveSignals`）：

```php
patterns = [
    'media_query'        => '/@media\s*\(\s*(?:max|min)-width\s*:/iu',
    'small_breakpoint'   => '/@media\s*\(\s*max-width\s*:\s*(?:4[0-9]{2}|3[0-9]{2})px/iu',
    'single_column'      => '/grid-template-columns\s*:\s*(?:minmax\(...\))?1fr|flex-direction:column/iu',
    'min_width_reset'    => '/min-width\s*:\s*0/iu',
    'media_responsive'   => '/(?:max-width:100%|height:auto|object-fit:cover)/iu',
    'overflow_guard'     => '/overflow-x:hidden|overflow-wrap:break-word/iu',
    'fluid_type_or_space'=> '/clamp\(|min\(|max\(/iu',
    'motion_reduced'     => '/prefers-reduced-motion/iu',
];
ok = isset(signals['media_query']) && count(signals) >= 4
```

**Prompt 反向编码**：

```
[gate#responsive_support] 响应式门禁 — 必含 @media，并在以下信号中再命中 ≥3 条（合计 ≥4）：
  1) `@media (max-width: 768px)` 至少一段（必含）
  2) `@media (max-width: 420px)` 单独一段
  3) 在窄屏断点内对分栏容器使用 `grid-template-columns:1fr` 或 `flex-direction:column`
  4) 移动断点内为子元素加 `min-width:0`
  5) 对图片 / 媒体加 `max-width:100%;height:auto;object-fit:cover` 至少一次
  6) 在 .pb-c-root 加 `overflow-x:hidden` 或 `overflow-wrap:break-word`
  7) 使用 `clamp(...)` / `min(...)` / `max(...)` 至少一次
  8) （可选加分）`@media (prefers-reduced-motion: reduce)`
```

---

## 3. theme_visible — 主题色可见门禁

**门禁判定**：`matchThemeHits` 计算页面 HTML 中出现的主题 hex token 数量。

**Prompt 反向编码**：

```
[gate#theme_visible] 主题可见门禁：
  - css_extra 中必须出现 themePalette 中至少 2 个 hex token
  - 不允许引入 themePalette 之外的高饱和品牌色（如随机紫红 / 荧光绿）

REQUIRED_PALETTE_ROLE_MAP (HARD)：
  {primary=..., accent=..., surface=..., text=..., cta_bg=..., cta_text=..., scrim=..., ...}
  ← css_extra 中所有颜色必须来自该字典；严禁使用以下硬编码模板色：
    #111827 / #f59e0b / #f8fafc / #92400e / #dc2626 / #8B0000 / #228B22 / #FFD700
    及其他任何不在 themePalette 中的 hex token。
```

**⚠ 关键约束**：
- 门禁通过阈值：`$themeHits !== []`（HTML 中至少命中 1 个非中性色调的 hex token）
- 中性色 #fff / #ffffff / #000 / #000000 不计入 theme_hits
- 若 scope 中无 theme token，门禁退化为 fallback 色表兜底判定（但此时审美已失败）

---

## 4. source_truth_coverage — 必含事实/必需区块/禁忌规则门禁

**门禁判定**（`AiSiteQualityGateService::collectQaReportFindings` → `SourceTruthCoverageLinter`）：

```php
// 来自 qa_report_contract.payload.content_quality.findings
// 判定维度：
//  - must_include_facts：阶段一确认的必含事实是否在页面 HTML 中可检索
//  - required_home_blocks：首页必需区块（hero/value_prop/social_proof/cta等）是否均有构建任务
//  - must_not_do / forbidden_style：禁忌规则是否被遵守
// ok = error_count === 0（仅 severity=error 阻断）
```

**Prompt 反向编码**（`AiSiteVisualBlockContractRenderer::renderMustIncludeFactsLine`）：

```
- must_include_facts (HARD)：可见文案至少自然包含以下事实（不要逐字硬贴，按 content_locale 改写）：
  {fact_1} | {fact_2} | {fact_3} ...
```

**不在 prompt 的部分**：required_home_blocks / forbidden_style 由 `SourceTruthContractBuilder` / `BuildPlanContractValidator` 在构建阶段校验，不写入组件生成 prompt。

---

## 5. render_data_quality — 渲染数据契约门禁

**门禁判定**（`AiSiteQualityGateService::collectQaReportFindings` → `RenderDataQualityLinter`）：

```php
// 来自 qa_report_contract.payload.content_quality.findings
// 判定维度（category=design/copy/seo）：
//  - 结构：标题/段落/CTA/form 层级是否合规
//  - 文案：空文案 / 占位文案 / demo 文案 / 纯英文模板词
//  - SEO：meta_title / meta_description 非空且非占位
// ok = error_count === 0
```

**Prompt 反向编码**（ContractRenderer `gate#render_data_quality`）：

```
[gate#render_data_quality] 渲染数据契约门禁（结构 / 文案 / SEO）：
  - 标题 h2 文案非空且 ≤120 字符，不能是 'Title' / 'Heading' / 占位词。
  - 主段 p 文案非空且 ≥40 字符；CTA 标签 ≤24 字符并匹配业务动作。
  - 每个 form input 必须有可见 label；alt/aria-label 不允许为空字符串。
```

---

## 6. content_quality — 内容洁净门禁

**门禁判定**（`AiSiteQualityGateService::matchBadContent` + `matchMalformedGeneratedHtml` + `matchLegacyDefaultBlocks`）：

```php
// matchBadContent(text):
//   多组正则匹配，覆盖以下类别：
//   1) 计划元数据泄漏：page_goal / block_goal / content_contract / design_contract 等字段名
//   2) 产品/虚拟文案："Introduce brand..." / "Showcase trust..." 等 plan 语言
//   3) Demo/占位词：lorem ipsum / TODO / placeholder / demo / 示例文案 / 占位
//   4) 内部标识：AI_GENERATED_SECTION / task_key / section_code / field_content_requirements
//   5) 外部资源标记：example.com / picsum.photos / unsplash.com 等
//   6) 策略描述泄漏："访客看到..." / "让访客..." / "从而产生信任感"
//   7) 中文计划语言："方案" / "蓝图" / "任务" / "本区块" / "设计意图" 等
// ok = badMatches === [] && legacyBlocks === []
```

**Prompt 反向编码**（ContractRenderer `gate#content_quality`）：

```
[gate#content_quality] 内容洁净门禁：
  - 严禁把 page_goal / block_goal / why_this_block / content_contract / design_contract /
    visual_contract / runtime_context / shared:/page:/content/ 这类元数据字符串直接渲染成可见 HTML。
  - 严禁出现 'lorem ipsum' / 'TODO' / 'placeholder' / 'sample text' / '示例文案' / 'demo' / '占位' 等 demo 字样。
```

---

## 7. stage1_content_visible — 阶段一内容可见门禁

**门禁判定**（`AiSiteQualityGateService::matchStageOneContent`）：

```php
// 从 execution_blueprint.pages[*].blocks[*] 提取阶段一确认的文本样本：
//   title / heading / headline / label / summary / description
//   field_plan[*].sample
//   realtime_content.headline / supporting_copy[*]
// 在渲染 HTML 中做子串匹配（mb_stripos）
// ok = $stageOneHits !== []（至少 1 条阶段一确认文本样本出现在页面中）
```

**Prompt 反向编码**（ContractRenderer `gate#stage1_content_visible`）：

```
[gate#stage1_content_visible] 阶段一内容可见门禁：
  - 可见文案必须从 must_include_facts / page_goal / block_goal 中提取真实业务名词、专有名词、数字、地名，
    不要写成「Welcome to our brand」之类的通用句。
```

---

## 8. language_consistency — 语言一致门禁

**门禁判定**（`AiSiteQualityGateService::matchLanguageViolations`）：

```php
// 判定逻辑（启发式）：
//   1) 从 scope 解析 expectedLocale（优先 content_locale → website_profile → plan_json）
//   2) 提取页面可见文本（strip 标签 + decode entities）
//   3) 移除品牌名/URL/域名/通用缩写(API/SEO/CTA等)白名单
//   4) CJK locale（zh/ja/ko）：检测大段拉丁字母散文（≥10词 或 ≥80字母，且含英文功能词）
//   5) Latin locale（en/fr/de/es...）：检测大段 CJK 文本（≥20连续字符）
// ok = $languageViolations === []
// ⚠ 只阻断大段错语种散文，品牌名/URL/缩写不受影响
```

**Prompt 反向编码**（ContractRenderer `content_locale (HARD)` + `gate#language_consistency`）：

```
- content_locale (HARD): {locale} — 全部可见文案（h2 / p / 卡片 / CTA / form label / 备注）
  必须用该语种书写；不要把 plan_locale 或英文模板词直接放进最终页面。

[gate#language_consistency] 语言一致门禁：
  - 每段可见文案（含 alt、placeholder、aria-label）必须使用 content_locale；混入其它语种文字会直接判负。
  - 数字、品牌名、URL 不受语种限制，但句子主干必须是 content_locale。
```

---

## 9. visual_assets_safe — 图片资源安全门禁

**门禁判定**（`AiSiteQualityGateService::inspectRenderedPage` visual_assets_safe 逻辑）：

```php
// 复合判定：
//   1) brokenImages === []（无破图/空src/外部CDN/占位图URL）
//   2) 若有图片需求（hasAnyImageNeed），页面必须有 <img> 或 SVG 视觉
//   3) 若 requiresRealImageAssets（asset_manifest.required=1 的 slot），
//      所有 required slot 必须在页面中被使用（data-pb-ai-asset-slot + 匹配 final_url）
//   4) 排除 fallback/placeholder/unresolved slot
// ok = 以上条件全部满足
```

**Prompt 反向编码**（ContractRenderer `gate#visual_assets_safe`）：

```
[gate#visual_assets_safe] 图片资源安全门禁：
  - 所有 <img src> 必须来自 verified_asset_src_allowlist；不要使用 example.com、unsplash、
    picsum、CDN 占位或 data:image/svg+xml。
  - 不允许出现 <svg>、空 src、broken alt（如 'image' / '...'）。alt 必须是 content_locale 的具体描述。
  - css_extra 里 url(...) 仅允许引用 verified_asset_src_allowlist 中的 URL，建议优先用 <img> 而非背景图。
```

---

## 10. shared_blocks_ready — 共享 Header/Footer 门禁

**门禁判定**（`AiSiteQualityGateService::detectSharedBlocks`）：

```php
// 从 layout.blocks 遍历：block_id 含 "header"/"footer" 或 type 含 "header"/"footer"
// 同时检查 build_blueprint.tasks 是否有 shared:header / shared:footer 任务
// ok = header && footer 均在 layout 中检测到（且若 blueprint 期望则任务也已调度）
```

**Prompt 反向编码**（ContractRenderer `renderSharedRegionVisualContract` + `gate#shared_blocks_ready`）：

```
AI Shared {header|footer} Contract — shared_blocks_ready 门禁反向编码：
  - content_locale (HARD): {locale}
  - theme_palette (HARD)：至少在 css_extra 中使用 ≥2 个 hex token
  - brand_words：必须出现品牌词 [brand1, brand2, ...] 至少一次
  - 视觉简约但有质感：使用 themePalette + 一处微妙渐变或细边框 + 一处 box-shadow
  - 不要输出图片标签；logo 由框架配置渲染
  - 不要使用元数据字符串、demo 文案、占位词

[gate#shared_blocks_ready] 共享 header/footer 协同门禁：
  - 内容区块的 .pb-c-* 类名不要与 header/footer 的 .pb-h-* / .pb-f-* 重名；不要复刻 header logo / 导航。
```

---

## 11. build_tasks_done — 构建任务全部门禁

**门禁判定**（`AiSiteBuildTaskService::inspectBuildCompletionGate`）：

```php
// 检查 scope 关联的所有构建任务状态：
//   total > 0 && done >= total && pending === 0 && running === 0 && failed === 0 && cancelled === 0
// ok = 所有任务 done（无 pending/running/failed/cancelled）
```

**不在 prompt 中**：此项由队列调度保证，AI 生成组件时不需要关心。

---

## 12. task_coverage — 任务覆盖门禁

**门禁判定**（`AiSiteQualityGateService::buildTaskCoverageReport`）：

```php
// 比较 execution_blueprint.pages[*].blocks[*].block_key（方案期望）
//   与 build_blueprint.tasks[*]（实际调度）：
//   - missing_blocks：方案中有但任务中无
//   - extra_blocks：任务中有但方案中无
//   - shared:header / shared:footer 单独检查
// ok = missing === [] && extra === []
// 若蓝图未生成（evaluated=false），不阻断
```

**不在 prompt 中**：此项由方案确认 → 任务拆分的流程保证，不在组件生成 prompt 中体现。

---

## 13. required_pages_render — 关键页面可渲染门禁

**门禁判定**（`AiSiteQualityGateService::inspectScope`）：

```php
// 对每个 pageType：
//   - 优先用 renderedHtmlByPageType 传入的覆盖值
//   - Virtual Theme 模式：renderVirtualThemePageForInspection 渲染虚拟页面
//   - 否则：pageModel.load(pageId) → pageRenderService.render(PREVIEW/LIVE 模式)
// ok = 所有 pageType 的 HTML 非空 && renderError 为空
```

**不在 prompt 中**：此项是运行时渲染结果检查，AI 不做自检。

---

## 附录 A：代码入口速查

| 角色 | 类/文件 | 关键方法 |
|------|---------|---------|
| 门禁总入口 | `AiSiteQualityGateService::inspectScope()` | 遍历 pageTypes → inspectRenderedPage → 汇总 13 项 |
| 内容门禁子集 | `AiSiteQualityGateService::inspectContentGate()` | 过滤掉 visual_depth / responsive / visuals 的 10 项子集 |
| visual_depth 判定 | `::matchVisualDepthSignals()` | 6 类信号 regex，≥3 通过 |
| responsive 判定 | `::matchResponsiveSignals()` | 8 类信号 regex，必含 media_query 且 ≥4 通过 |
| theme 判定 | `::matchThemeTokens()` | scope 递归提取 hex token → HTML 子串匹配 |
| content 洁净判定 | `::matchBadContent()` + `::matchPromptPlanningLeakText()` | 50+ 正则覆盖计划元数据/占位/demo/策略描述泄漏 |
| 语言一致判定 | `::matchLanguageViolations()` | CJK/Latin 双向大段散文检测 |
| 图片安全判定 | `::matchBrokenImages()` + `::matchUsedRequiredImageSlotIds()` | 破图/外部CDN/required slot 未使用 |
| 阶段一内容判定 | `::matchStageOneContent()` | 从 execution_blueprint 提取样本 → HTML 子串匹配 |
| prompt 契约渲染 | `AiSiteVisualBlockContractRenderer::renderSectionVisualContract()` | 13 项反向编码为 AI 自检清单 |
| 共享部件 prompt | `AiSiteVisualBlockContractRenderer::renderSharedRegionVisualContract()` | header/footer 单独契约 |
| 结构化异常 | `AiSiteComponentContractException` | findings[] + renderFindingsForPrompt() |
| 生成服务 | `AiSitePageComponentGenerationService` | ensureAiPayloadValid / buildStrictComponentRecoveryPrompt |

---

## 附录 B：变更记录

| 日期 | 变更 |
|------|------|
| 2026-05-17 | 初版：§0–§3（visual_depth / responsive / theme_visible 前半），补全 §3 后半–§13 + 附录 A/B |
| v3 | 消除 prompt 与门禁打架：允许 gradient/@media/clamp，上调 CSS 预算，取消硬编码 SAFE 色 |
| v3 | 新增 `AiSiteVisualBlockContractRenderer`：门禁规则反向编码为 AI 自检清单 |
| v3 | 新增 `AiSiteComponentContractException`：findings 结构化失败回灌 |
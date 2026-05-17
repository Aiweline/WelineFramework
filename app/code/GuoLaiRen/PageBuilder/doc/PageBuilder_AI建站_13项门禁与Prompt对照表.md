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
    'single_column'      => '/grid-template-columns\s*:\s*(?:minmax\(...\)?1fr|flex-direction:column/iu',
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
  ← css_extra 中所有颜色必须来自该字典；禁用所有硬编码模板色（#111827 / #f59e0b 等）
```

---

## 4. visual_assets_safe — 图片资源安全门禁

**门禁判定**：`inspectRenderedPage.visuals_safe`：
- 所有 `<img src>` 必须出现在 `verified_asset_src_allowlist`
- 不允许 `<svg>`、`data:image/svg+xml`、unsplash/pexels/picsum 等外站
- 缺图位不允许出现破图

**Prompt 反向编码**：

```
[gate#visual_assets_safe] 图片资源安全门禁：
  - 所有 <img src> 必须来自 verified_asset_src_allowlist
  - 不允许出现 <svg>、空 src、broken alt
  - css_extra 里 url(...) 仅允许引用 verified_asset_src_allowlist 中的 URL
```

---

## 5. language_consistency — 语言一致门禁

**门禁判定**（`matchLanguageViolations`）：检查可见文案非 content_locale 的字符比例。

**Prompt 反向编码**：

```
content_locale (HARD): {locale}
  - 全部可见文案（h2 / p / 卡片 / CTA / form label / 备注）必须用该语种书写
  - 不要把 plan_locale 或英文模板词直接放进最终页面

[gate#language_consistency] 语言一致门禁：
  - 每段可见文案（含 alt、placeholder、aria-label）必须使用 content_locale
  - 数字、品牌名、URL 不受语种限制
```

---

## 6. source_truth_coverage / stage1_content_visible

**门禁判定**：
- `SourceTruthCoverageLinter` 计算 must_include_facts、必需区块、禁忌规则的命中率
- `matchStageOneContent` 计算阶段一关键样本在 HTML 中的出现率

**Prompt 反向编码**：

```
must_include_facts (HARD)：可见文案至少自然包含以下事实（不要逐字硬贴，按 content_locale 改写）：
  Free APK | Daily bonus 100 | Verified safe | ...

[gate#stage1_content_visible] 阶段一内容可见门禁：
  - 可见文案必须从 must_include_facts / page_goal / block_goal 中提取真实业务名词
```

---

## 7. content_quality — 内容洁净门禁

**门禁判定**：`inspectRenderedPage.content_clean` —— 检测页面是否仍包含 metadata 字符串、demo 字样、契约关键字。

**Prompt 反向编码**：

```
[gate#content_quality] 内容洁净门禁：
  - 严禁把 page_goal / block_goal / why_this_block / content_contract /
    design_contract / visual_contract / runtime_context / shared:/page:/
    content/ 这类元数据字符串直接渲染成可见 HTML。
  - 严禁出现 'lorem ipsum' / 'TODO' / 'placeholder' / 'demo' / '占
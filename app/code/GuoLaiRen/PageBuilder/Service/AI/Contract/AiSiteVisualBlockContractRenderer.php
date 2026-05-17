<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Contract;

/**
 * AI 站点视觉契约渲染器。
 *
 * 设计目标：
 *   AiSiteQualityGateService 里 13 项强契约门禁的判定规则（responsive_signals /
 *   visual_depth_signals / language_violations / visuals / stage1_hits /
 *   theme_hits 等）原本只在 PHP 端校验，AI 在生成时只能靠经验猜测什么样写法
 *   会通过。本渲染器把这些判定规则反向编码成一份「AI 自检清单」，直接拼到
 *   组件生成 prompt 里，让 AI 在产出前先按相同规则做自检。
 *
 *   该清单不是补丁，也不重复 buildSectionOutputRulesPromptAddon 的 schema 规则，
 *   它只承担一件事：把 13 项门禁信号变成 AI 可以自查的可执行行为清单，避免
 *   「prompt 让 AI 不写 @media，但门禁却要求 @media」这类自相矛盾。
 *
 *   每一项 buildXxx 的输出都对应 QUALITY_ITEM_SPECS 里的一项 page_report_field，
 *   并按门禁的真实匹配模式列出 AI 必须命中的关键字 / 行为。
 */
final class AiSiteVisualBlockContractRenderer
{
    /**
     * 渲染单个内容区块的视觉契约 prompt 段（接入 buildSectionGenerationPrompt）。
     *
     * @param array<string,mixed> $themePalette   阶段一确认的主题色板（hex token / role => hex）
     * @param array<string,mixed> $brief          区块意图（page_goal / block_goal / must_include_facts ...）
     * @param string              $contentLocale  必须使用的展示语种（影响 language_consistency 门禁）
     */
    public function renderSectionVisualContract(
        array $themePalette,
        array $brief,
        string $contentLocale,
        bool $hasVerifiedHeroImage = false
    ): string {
        $contentLocale = \trim($contentLocale);
        $localeLine = $contentLocale !== ''
            ? "- content_locale (HARD): {$contentLocale} — 全部可见文案（h2 / p / 卡片 / CTA / form label / 备注）必须用该语种书写；不要把 plan_locale 或英文模板词直接放进最终页面。"
            : "- content_locale 未指定：使用 scope 默认语种；任何非默认语种字符都会触发 language_consistency 门禁。";

        $themeLine = $this->renderThemePaletteLine($themePalette);
        $factsLine = $this->renderMustIncludeFactsLine($brief);

        $heroNote = $hasVerifiedHeroImage
            ? "- visual.image：必须保留已验证的 <img>，类名 .pb-c-hero-img / .pb-c-img 不变，src 来自 final_url；不要替换成 background-image 或 svg。"
            : "- visual.image：本区块没有验证后的图片素材时，用 CSS-only 视觉语言（layered background + pseudo-element + shape motif）替代，不要输出 <img> 也不要使用 <svg>。";

        $sections = [];

        $sections[] = "AI Visual Block Contract — 13 项门禁反向编码（生成前必须自检）";
        $sections[] = $localeLine;
        $sections[] = $themeLine;
        $sections[] = $factsLine;
        $sections[] = $heroNote;

        $sections[] = "\n[gate#visual_depth] 视觉层次门禁 — 需在 css_extra / html_content 中至少命中 3 条；建议同时命中 4 条以确保稳定通过：";
        $sections[] = "  1) gradient：至少出现一次 linear-gradient(...) 或 radial-gradient(...)，用于背景层 / 边框装饰。";
        $sections[] = "  2) shadow：box-shadow 至少出现一次，给卡片 / CTA / 浮层添加层级。";
        $sections[] = "  3) visual：保留 <img data-pb-ai-image-role=...> 或 url(...) 背景 / vt-visual 容器 / pseudo-element（::before/::after）以呈现视觉主体。";
        $sections[] = "  4) layout：display:grid 或 display:flex 至少一次，并搭配 grid-template-columns / gap / align-items 形成结构。";
        $sections[] = "  5) motion：transition: 至少一次，覆盖 hover / focus / active 状态。";
        $sections[] = "  6) surface：border-radius 至少一次；可选 backdrop-filter 或 color-mix(...) 增强精致度。";

        $sections[] = "\n[gate#responsive_support] 响应式门禁 — 必含 @media，并在以下信号中再命中 ≥3 条（合计 ≥4 条）：";
        $sections[] = "  1) `@media (max-width: 768px)` 至少一段（必含）。";
        $sections[] = "  2) `@media (max-width: 420px)` 单独一段，处理移动端。";
        $sections[] = "  3) 在窄屏断点内对分栏容器使用 `grid-template-columns:1fr` 或 `flex-direction:column` 让其堆叠。";
        $sections[] = "  4) 移动断点内为子元素加 `min-width:0`，避免 flex 溢出。";
        $sections[] = "  5) 对图片 / 媒体加 `max-width:100%;height:auto;object-fit:cover` 至少一次。";
        $sections[] = "  6) 在 .pb-c-root 或主容器加 `overflow-x:hidden` 或 `overflow-wrap:break-word`。";
        $sections[] = "  7) 使用 `clamp(...)` / `min(...)` / `max(...)` 至少一次，做字号或间距流式缩放。";
        $sections[] = "  8) （可选加分）`@media (prefers-reduced-motion: reduce)`，把 transition / animation 关掉。";

        $sections[] = "\n[gate#visual_assets_safe] 图片资源安全门禁：";
        $sections[] = "  - 所有 <img src> 必须来自 verified_asset_src_allowlist；不要使用 example.com、unsplash、picsum、CDN 占位或 data:image/svg+xml。";
        $sections[] = "  - 不允许出现 <svg>、空 src、broken alt（如 'image' / '...'）。alt 必须是 content_locale 的具体描述。";
        $sections[] = "  - css_extra 里 url(...) 仅允许引用 verified_asset_src_allowlist 中的 URL，建议优先用 <img> 而非背景图。";

        $sections[] = "\n[gate#theme_visible] 主题可见门禁：";
        $sections[] = "  - css_extra 中必须出现 themePalette 中至少 2 个 hex token（如主色 / 强调色 / 中性色），不允许全部用 #fff #000 这种通用色。";
        $sections[] = "  - 不允许引入 themePalette 之外的高饱和品牌色（如随机紫红 / 荧光绿）。";

        $sections[] = "\n[gate#stage1_content_visible] 阶段一内容可见门禁：";
        $sections[] = "  - 可见文案必须从 must_include_facts / page_goal / block_goal 中提取真实业务名词、专有名词、数字、地名，不要写成「Welcome to our brand」之类的通用句。";

        $sections[] = "\n[gate#content_quality] 内容洁净门禁：";
        $sections[] = "  - 严禁把 page_goal / block_goal / why_this_block / content_contract / design_contract / visual_contract / runtime_context / shared:/page:/content/ 这类元数据字符串直接渲染成可见 HTML。";
        $sections[] = "  - 严禁出现 'lorem ipsum' / 'TODO' / 'placeholder' / 'sample text' / '示例文案' / 'demo' / '占位' 等 demo 字样。";

        $sections[] = "\n[gate#language_consistency] 语言一致门禁：";
        $sections[] = "  - 每段可见文案（含 alt、placeholder、aria-label）必须使用 content_locale；混入其它语种文字会直接判负。";
        $sections[] = "  - 数字、品牌名、URL 不受语种限制，但句子主干必须是 content_locale。";

        $sections[] = "\n[gate#render_data_quality] 渲染数据契约门禁（结构 / 文案 / SEO）：";
        $sections[] = "  - 标题 h2 文案非空且 ≤120 字符，不能是 'Title' / 'Heading' / 占位词。";
        $sections[] = "  - 主段 p 文案非空且 ≥40 字符；CTA 标签 ≤24 字符并匹配业务动作（download/play/reward/consult/contact 视类型而定）。";
        $sections[] = "  - 每个 form input 必须有可见 label；alt/aria-label 不允许为空字符串。";

        $sections[] = "\n[gate#shared_blocks_ready] 共享 header/footer 协同门禁：";
        $sections[] = "  - 内容区块的 .pb-c-* 类名不要与 header/footer 的 .pb-h-* / .pb-f-* 重名；不要复刻 header logo / 导航。";

        $sections[] = "\n[self-check before return] AI 必须在返回 JSON 前自查：";
        $sections[] = "  - visual_depth 信号 ≥ 3 / responsive 信号 ≥ 4 且含 @media？若不足，请在 css_extra / css_responsive 内补齐。";
        $sections[] = "  - 是否使用了 themePalette 的 ≥2 个 hex token？所有 <img src> 是否来自 verified_asset_src_allowlist？";
        $sections[] = "  - 可见文案是否全部 content_locale，且不含元数据 / demo 字样？";

        return \implode("\n", $sections) . "\n";
    }

    /**
     * 渲染共享 header/footer 的视觉契约 prompt 段（接入 generateSharedComponents）。
     *
     * 共享部件门禁判定较宽松（主要走 shared_blocks_ready），但仍需要复用站点 palette
     * 与品牌词，避免与内容块视觉断裂。
     */
    public function renderSharedRegionVisualContract(
        string $region,
        array $themePalette,
        array $brief,
        string $contentLocale
    ): string {
        $contentLocale = \trim($contentLocale);
        $brandWords = $this->collectBrandWords($brief);
        $themeLine = $this->renderThemePaletteLine($themePalette);
        $brandLine = $brandWords !== []
            ? "- brand_words：必须出现品牌词 [" . \implode(', ', \array_slice($brandWords, 0, 3)) . "] 至少一次（在 footer_extra_text 或 logo alt 中）。"
            : "- brand_words：未提供品牌词，使用 scope 上下文的 site_title / site_brand。";

        return "AI Shared {$region} Contract — shared_blocks_ready 门禁反向编码：\n"
            . "- content_locale (HARD): {$contentLocale}，所有可见文案使用该语种。\n"
            . $themeLine . "\n"
            . $brandLine . "\n"
            . "- 视觉简约但有质感：使用 themePalette 的中性色/强调色 + 一处微妙渐变或细边框 + 一处 box-shadow；不要重新设计页面板块。\n"
            . "- 不要输出图片标签；logo 由框架配置渲染。\n"
            . "- 不要使用元数据字符串、demo 文案、占位词；不要复刻其它区块的卡片结构。\n";
    }

    private function renderThemePaletteLine(array $themePalette): string
    {
        $hexes = [];
        foreach ($themePalette as $key => $value) {
            if (\is_string($value) && \preg_match('/^#[0-9a-fA-F]{3,8}$/', $value) === 1) {
                $hexes[] = $key . '=' . \strtolower($value);
            } elseif (\is_array($value)) {
                foreach ($value as $nestedKey => $nestedValue) {
                    if (\is_string($nestedValue) && \preg_match('/^#[0-9a-fA-F]{3,8}$/', $nestedValue) === 1) {
                        $hexes[] = $key . '.' . $nestedKey . '=' . \strtolower($nestedValue);
                    }
                }
            }
        }
        $hexes = \array_values(\array_unique($hexes));
        if ($hexes === []) {
            return "- theme_palette：当前 scope 未提供 hex token，使用 design 上下文中显式给出的颜色变量，禁止凭空发明品牌色。";
        }

        return "- theme_palette (HARD)：必须在 css_extra 中至少使用以下 ≥2 个 hex token —— " . \implode(' / ', \array_slice($hexes, 0, 8));
    }

    private function renderMustIncludeFactsLine(array $brief): string
    {
        $facts = [];
        $candidates = $brief['must_include_facts'] ?? $brief['must_include'] ?? [];
        if (\is_array($candidates)) {
            foreach ($candidates as $fact) {
                if (\is_string($fact) && \trim($fact) !== '') {
                    $facts[] = \trim($fact);
                }
            }
        }
        if ($facts === []) {
            return "- must_include_facts：未声明强制事实；可见文案仍需源自 block_goal / page_goal，禁止凭空发明业务卖点。";
        }

        return "- must_include_facts (HARD)：可见文案至少自然包含以下事实（不要逐字硬贴，按 content_locale 改写）：" . \implode(' | ', \array_slice($facts, 0, 6));
    }

    /**
     * @param array<string,mixed> $brief
     * @return list<string>
     */
    private function collectBrandWords(array $brief): array
    {
        $words = [];
        foreach (['site_title', 'site_brand', 'brand_name', 'brand'] as $key) {
            $value = $brief[$key] ?? null;
            if (\is_string($value) && \trim($value) !== '') {
                $words[] = \trim($value);
            }
        }

        return \array_values(\array_unique($words));
    }
}

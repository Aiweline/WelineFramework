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
        bool $hasVerifiedHeroImage = false,
        array $visualSignature = [],
        array $pageDesignPlan = []
    ): string {
        $contentLocale = \trim($contentLocale);
        $localeLine = $contentLocale !== ''
            ? "- content_locale (HARD): {$contentLocale} — 全部可见文案（h2 / p / 卡片 / CTA / form label / 备注）必须用该语种书写；不要把 plan_locale 或英文模板词直接放进最终页面。"
            : "- content_locale 未指定：使用 scope 默认语种；任何非默认语种字符都会触发 language_consistency 门禁。";

        $themeLine = $this->renderThemePaletteLine($themePalette);
        $factsLine = $this->renderMustIncludeFactsLine($brief);
        $roleLine = $this->renderCurrentBlockRoleLine($brief);
        $visualSignatureLine = $this->renderVisualSignatureLine($visualSignature);
        $pageDesignLine = $this->renderPageDesignPlanLine($pageDesignPlan);

        $heroNote = $hasVerifiedHeroImage
            ? "- visual.image：必须保留已验证的 <img>，类名 .pb-c-hero-img / .pb-c-img 不变，src 来自 final_url；不要替换成 background-image 或 svg。"
            : "- visual.image：本区块没有验证后的图片素材时，用 CSS-only 视觉语言（layered background + pseudo-element + shape motif）替代，不要输出 <img> 也不要使用 <svg>。";

        $sections = [];

        $sections[] = "AI Visual Block Contract — 13 项门禁反向编码（生成前必须自检）";
        $sections[] = $localeLine;
        $sections[] = $themeLine;
        $sections[] = $factsLine;
        $sections[] = $roleLine;
        if ($visualSignatureLine !== '') {
            $sections[] = $visualSignatureLine;
        }
        if ($pageDesignLine !== '') {
            $sections[] = $pageDesignLine;
        }
        $sections[] = $heroNote;
        $sections[] = "- framework wrapper rhythm baseline: content blocks own spacing in `.pb-c-root`; css_extra should include `#componentId{padding:0;}` so PageBuilder's mount section does not add a second top/bottom gutter. Keep non-hero proof/support roots compact, normally 44-64px vertical padding unless a large verified media layer needs more room.";
        $sections[] = "- hero/opening full-bleed baseline: when current_block_role indicates hero/banner/opening or a verified .pb-c-hero-img is supplied, css_extra includes `#componentId{padding:0;}` and the root shell spans the viewport with width:100vw or min-width:100vw plus margin:0 calc(50% - 50vw); only the inner/text panel may be max-width constrained. A centered 1200px image island or top/bottom theme-color gutters around the hero image are invalid unless the customer's latest instruction explicitly limits the banner width.";
        $sections[] = "- spacing rhythm baseline: CTA/action groups must have deliberate breathing room. If a CTA follows text, channel rows, form fields, FAQ rows, or dividers, place it in a sibling `.pb-c-action`/`.pb-c-actions` wrapper after the rows/forms/cards and separate it with outer margin-top/padding-top, parent flex/grid gap, or bottom spacing on the preceding row group. The CTA button's own padding is internal button shape and does not count as clearance from a divider line.";

        $sections[] = "\n[gate#visual_depth] 视觉层次门禁 — 需在 css_extra / html_content 中至少命中 3 条；建议同时命中 4 条以确保稳定通过：";
        $sections[] = "  1) gradient：至少出现一次 linear-gradient(...) 或 radial-gradient(...)，用于背景层 / 边框装饰。";
        $sections[] = "  2) shadow：box-shadow 至少出现一次，给卡片 / CTA / 浮层添加层级。";
        $sections[] = "  3) visual：保留 <img data-pb-ai-image-role=...> 或 url(...) 背景 / vt-visual 容器 / pseudo-element（::before/::after）以呈现视觉主体。";
        $sections[] = "  4) layout：用 display:grid 或 display:flex 形成清晰结构（分栏、步骤轨、proof 带、FAQ 行、表单区均可）；不要为了凑门禁把每个区块都做成「标题 + 三列卡片」。";
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
        $sections[] = "  9) CTA selector exception: responsive width:100% applies to containers/media/forms first. The actual CTA button should remain a recognizable button in the default layout and must not become a desktop page-width bar. Prefer .pb-c-action or .pb-c-actions for wrappers so wrapper CSS is not mistaken for button CSS.";
        $sections[] = "  10) Typography quality: css_extra must define explicit font-family declarations for both `#componentId .pb-c-title` and one body/root selector (`#componentId .pb-c-root`, `#componentId .pb-c-copy`, or `#componentId .pb-c-text`). Each stack starts with a named brand family before generic fallback; pure system-ui/-apple-system/Segoe UI/Roboto/Arial/sans-serif alone is invalid.";

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

        $sections[] = "\n[gate#block_role_fidelity] Current block role gate:";
        $sections[] = "  - task_key, section_code, block_key, page_flow_role, and block_goal are binding generation constraints. Generate only this block role; never reuse another block's headline, image, CTA, card grid, or contact-method layout to pass quickly.";
        $sections[] = "  - FAQ/support-question roles must render explicit question-answer groups inside `.pb-c-faq-list`: each item uses `<div class='pb-c-faq-item'><div class='pb-c-question'>Question?</div><p class='pb-c-answer'>Answer.</p></div>`. Style `.pb-c-faq-item` as a visible surface with padding, border-radius, and background/border/shadow. Do not use inline strong+span markup that visually glues question?Answer. They must not render requirement/stat cards or contact method grids.";
        $sections[] = "  - Form-guidance roles must render a real designed `<form class='pb-c-form'>` with repeated `.pb-c-field` groups, visible `.pb-c-label` labels, `.pb-c-input` controls, and a `.pb-c-textarea` message field. css_extra must style `.pb-c-form`, `.pb-c-field`, `.pb-c-label`, `.pb-c-input`, and `.pb-c-textarea` with column/grid rhythm, gap, width:100%, padding, border-radius, border/background, box-sizing:border-box, and focus states; naked inline browser controls fail. They must not render email/phone/address cards. CTA roles must render one focused next-step band and must not output FAQ classes (`pb-c-faq-item`, `pb-c-question`, `pb-c-answer`), question-answer copy, or repeated email/phone/office/hours card grids. Contact-method roles must render at least two visible channel items with `.pb-c-label`/`.pb-c-value` siblings and a distinct channel rail/console/strip; only use email/phone/address/hours/WhatsApp values when the source facts provide exact real values, otherwise use localized support promises. A verified image may support the atmosphere but cannot replace the channel hub. If the contact-method block includes a CTA, wrap it in a sibling `.pb-c-action` after the channel group and separate it with outer margin/padding/gap so it never touches channel dividers.";
        $sections[] = "  - Policy-page body CTA rule: if page_type is privacy_policy, terms_of_service, refund_policy, shipping_policy, or cookie_policy, visible block CTAs must be about policy information, data rights, terms review, consent management, or support. Do not render download/APK/install/play/bonus/reward CTA text inside policy page blocks just because the global site CTA uses that language.";
        $sections[] = "  - These identifiers are not visitor copy. Use them to choose structure and content, then rewrite everything visible into final customer-facing language.";

        $sections[] = "\n[gate#field_readability] Label/value readability gate:";
        $sections[] = "  - Facts such as email, phone, address, hours, metrics, prices, support labels, and FAQ answers must have visible separation from their labels/questions with punctuation and sibling block elements such as .pb-c-label/.pb-c-value or .pb-c-question/.pb-c-answer.";
        $sections[] = "  - Invalid visible output examples: EmailSupport, Email:support, support@example.com, support@ .com, Phone+91, Address42, HoursMonday, Android VersionRequires, Storage SpaceMinimum, PermissionsAllow, Android version required?Android, paragraph text glued directly to CTA text. Rewrite into readable sibling elements before returning JSON.";

        $sections[] = "\n[gate#same_page_block_diversity] Same-page diversity gate:";
        $sections[] = "  - Treat stage-1 visual_signature (composition_pattern / spatial_rhythm / media_strategy / surface_treatment / interaction_pattern) as the primary layout contract for this block. If CTX_BLOCK_VISUAL_SIGNATURE or current_block_context lists a pattern, implement that pattern instead of a generic split panel or three-card grid.";
        $sections[] = "  - Adjacent blocks on the same page must vary composition: split panel, stacked editorial, step rail, proof/metric band, FAQ rows, form guidance, CTA band, media feature, or channel hub — not the same shell with swapped copy.";
        $sections[] = "  - Repeating one dark slab plus the same accent cards/grid for unrelated roles is invalid, even if colors match the theme.";
        $sections[] = "  - Default role composition baseline: opening/contact-method blocks favor a channel hub, rail, console, or media-backed help desk; form-guidance blocks favor a real form surface plus guidance copy; FAQ blocks favor stacked question-answer rows or accordion surfaces; final CTA blocks favor one distilled action band with compact proof. These role baselines must not collapse into one reused card-grid shell.";
        $sections[] = "  - Theme-following rule: keep palette, contrast, and typography consistent across the page, but change composition, scale, media placement, and surface rhythm by role. 'Same colors + same panel shell + different text' still counts as monotony and fails.";
        $sections[] = "  - Gate compliance is not an excuse for template sameness: meeting gradient/shadow/@media signals must not force every block into identical card grids or metric rows.";

        $sections[] = "\n[gate#language_consistency] 语言一致门禁：";
        $sections[] = "  - 每段可见文案（含 alt、placeholder、aria-label）必须使用 content_locale；混入其它语种文字会直接判负。";
        $sections[] = "  - 数字、品牌名、URL 不受语种限制，但句子主干必须是 content_locale。";
        $sections[] = "  - Contact fact source lock: email/phone/WhatsApp/address/hour values are real facts. Output them only when the exact value is present in the source facts; never derive support@... from a brand/domain or invent a phone number.";

        $sections[] = "\n[gate#render_data_quality] 渲染数据契约门禁（结构 / 文案 / SEO）：";
        $sections[] = "  - 标题 h2 文案非空且 ≤120 字符，不能是 'Title' / 'Heading' / 占位词。";
        $sections[] = "  - 主段 p 文案非空且 ≥40 字符；CTA 标签 ≤24 字符并匹配业务动作（download/play/reward/consult/contact 视类型而定）。";
        $sections[] = "  - 每个 form input 必须有可见 label；alt/aria-label 不允许为空字符串。";

        $sections[] = "\n[gate#shared_blocks_ready] 共享 header/footer 协同门禁：";
        $sections[] = "  - 内容区块的 .pb-c-* 类名不要与 header/footer 的 .pb-h-* / .pb-f-* 重名；不要复刻 header logo / 导航。";

        $sections[] = "\n[self-check before return] AI 必须在返回 JSON 前自查：";
        $sections[] = "  - visual_depth 信号 ≥ 3 / responsive 信号 ≥ 4 且含 @media？若不足，请在 css_extra / css_responsive 内补齐；补齐方式须服从 visual_signature 与 block role，禁止用「标题+三列卡片」模板凑数。";
        $sections[] = "  - 是否使用了 themePalette 的 ≥2 个 hex token？所有 <img src> 是否来自 verified_asset_src_allowlist？";
        $sections[] = "  - 可见文案是否全部 content_locale，且不含元数据 / demo 字样？";
        $sections[] = "  - 本区块 composition / media / surface 是否与 CTX_BLOCK_VISUAL_SIGNATURE、CTX_SIBLING_BLOCK_COMPOSITIONS 一致且彼此区分？若与相邻区块仍是同一壳层，先改布局再返回。";

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
            . "- Footer/header text is optional and must be target-locale visitor copy. Do not copy the approved brief, source objective, source truth, or English brand summary verbatim; leave optional text empty if no target-locale sentence can be safely synthesized.\n"
            . "- 视觉简约但有质感：使用 themePalette 的中性色/强调色 + 一处微妙渐变或细边框 + 一处 box-shadow；不要重新设计页面板块。\n"
            . "- 不要输出图片标签；logo 由框架配置渲染。\n"
            . "- 不要使用元数据字符串、demo 文案、占位词；不要复刻其它区块的卡片结构。\n";
    }

    /**
     * @param array<string,mixed> $visualSignature
     */
    private function renderVisualSignatureLine(array $visualSignature): string
    {
        if ($visualSignature === []) {
            return '';
        }

        $parts = [];
        foreach ([
            'composition_pattern',
            'spatial_rhythm',
            'media_strategy',
            'surface_treatment',
            'interaction_pattern',
        ] as $key) {
            $value = $visualSignature[$key] ?? null;
            if (!\is_scalar($value) && !(\is_object($value) && \method_exists($value, '__toString'))) {
                continue;
            }
            $value = $this->compactPromptValue((string)$value);
            if ($value !== '') {
                $parts[] = $key . '=' . $value;
            }
        }

        if ($parts === []) {
            return '';
        }

        return '- visual_signature (HARD layout contract): ' . \implode(' / ', $parts)
            . '. Implement this exact composition rhythm; do not substitute a generic hero split or three-card grid unless composition_pattern explicitly calls for cards.';
    }

    /**
     * @param array<string,mixed> $pageDesignPlan
     */
    private function renderPageDesignPlanLine(array $pageDesignPlan): string
    {
        if ($pageDesignPlan === []) {
            return '';
        }

        $parts = [];
        foreach ([
            'composition_motif',
            'visual_hierarchy',
            'color_layering',
            'anti_monotony_rule',
            'section_flow',
        ] as $key) {
            $value = $pageDesignPlan[$key] ?? null;
            if (\is_array($value)) {
                $items = [];
                foreach ($value as $item) {
                    if (\is_string($item) && \trim($item) !== '') {
                        $items[] = \trim($item);
                    }
                }
                $value = $items === [] ? '' : \implode(' > ', \array_slice($items, 0, 5));
            }
            if (!\is_scalar($value) && !(\is_object($value) && \method_exists($value, '__toString'))) {
                continue;
            }
            $value = $this->compactPromptValue((string)$value);
            if ($value !== '') {
                $parts[] = $key . '=' . $value;
            }
        }

        if ($parts === []) {
            return '';
        }

        return '- page_design_plan (page-level design brief): ' . \implode(' / ', \array_slice($parts, 0, 5));
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

    private function renderCurrentBlockRoleLine(array $brief): string
    {
        $parts = [];
        foreach ([
            'task_key',
            'section_code',
            'block_key',
            'page_type',
            'page_flow_role',
            'role_fidelity_hint',
            'block_goal',
            'stage1_block_content',
        ] as $key) {
            $value = $brief[$key] ?? null;
            if (!\is_scalar($value) && !(\is_object($value) && \method_exists($value, '__toString'))) {
                continue;
            }
            $value = $this->compactPromptValue((string)$value);
            if ($value !== '') {
                $parts[] = $key . '=' . $value;
            }
        }

        if ($parts === []) {
            return "- current_block_role (HARD): no explicit task identifiers were supplied; use page_goal, block_goal, and must_include_facts only. Do not reuse another block's structure or copy.";
        }

        return "- current_block_role (HARD): " . \implode(' / ', \array_slice($parts, 0, 8)) . ". Treat these as generation constraints, not visitor-visible copy.";
    }

    private function compactPromptValue(string $value): string
    {
        $value = \trim((string)\preg_replace('/\s+/u', ' ', $value));
        if ($value === '') {
            return '';
        }

        if (\function_exists('mb_strlen') && \function_exists('mb_substr')) {
            return \mb_strlen($value) > 120 ? \mb_substr($value, 0, 117) . '...' : $value;
        }

        return \strlen($value) > 120 ? \substr($value, 0, 117) . '...' : $value;
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

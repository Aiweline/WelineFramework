<?php

declare(strict_types=1);

namespace Weline\Websites\Service\AiWorkbench;

use Weline\Ai\Api\AiRuntimeInterface;
use Weline\Framework\Runtime\RuntimeProviderResolver;

class PlanGenerationService
{
    private const PLAN_SCENARIO_CODE = null;

    /** @var list<string> */
    private const SUPPORTED_PAGE_TYPES = [
        'home_page',
        'about_page',
        'contact_page',
        'privacy_policy',
        'terms_of_service',
        'refund_policy',
        'shipping_policy',
        'cookie_policy',
        'blog_post',
        'blog_category',
        'blog_list',
        'custom_page',
    ];

    private bool $aiRuntimeResolved = false;
    private ?AiRuntimeInterface $aiRuntime = null;

    public function __construct(
        private readonly RuntimeProviderResolver $runtimeProviderResolver,
    ) {
    }

    /**
     * @param array<string, mixed> $draftPayload
     * @return array<string, mixed>
     */
    public function generatePlan(array $draftPayload, string $userMessage, ?callable $emit = null): array
    {
        $userMessage = \trim($userMessage);
        $brief = $this->pickString(
            $draftPayload['description'] ?? null,
            $draftPayload['initial_description'] ?? null,
            \is_array($draftPayload['current_plan'] ?? null) ? ($draftPayload['current_plan']['brief_description'] ?? null) : null,
            $userMessage
        );
        $references = $this->normalizeReferenceList($draftPayload['reference_urls'] ?? []);
        $conversation = $this->normalizeConversation($draftPayload['chat_messages'] ?? []);
        $currentPlan = \is_array($draftPayload['current_plan'] ?? null) ? $draftPayload['current_plan'] : [];
        $fakeMode = $this->isTruthyFlag($draftPayload['fake_mode'] ?? false);

        if ($emit !== null) {
            $emit('status', [
                'message' => (string)__('Starting plan generation'),
            ]);
        }

        $generated = $fakeMode
            ? $this->buildFakePlan($brief, $references, $userMessage, $emit)
            : $this->streamAiPlan($brief, $references, $conversation, $currentPlan, $userMessage, $emit);

        $generated['build_mode'] = $this->normalizeBuildMode((string)($generated['build_mode'] ?? ''));
        $generated['page_types'] = $this->normalizeGeneratedPageTypes(
            $generated['page_types'] ?? [],
            $brief,
            $currentPlan,
            $userMessage
        );
        $generated['plan_markdown'] = $this->buildPlanMarkdown($generated);
        $generated['reference_urls'] = $references;
        $updated = $userMessage !== '' ? $userMessage : $brief;
        if (\mb_strlen($updated) > 500) {
            $updated = (string)\mb_substr($updated, 0, 500) . '…';
        }
        $generated['updated_from_message'] = $updated;
        if (isset($generated['brief_description']) && \is_string($generated['brief_description']) && \mb_strlen($generated['brief_description']) > 500) {
            $generated['brief_description'] = (string)\mb_substr($generated['brief_description'], 0, 500) . '…';
        }
        if ($fakeMode) {
            $generated['fake_mode'] = 1;
        }

        if ($emit !== null) {
            $emit('plan_completed', [
                'message' => (string)__('Plan generation completed'),
                'plan' => $generated,
            ]);
        }

        return $generated;
    }

    /**
     * Rewrite a site brief into a structured, production-ready requirement document.
     *
     * Output follows the ChronoAI-style sections:
     * Role & System Prompt / Project Context / Architecture & Layout /
     * Interactive Requirements / Code Quality & Constraints.
     *
     * @return array{success:bool,message:string,description?:string}
     */
    public function polishDescription(
        string $description,
        bool $fakeMode = false,
        string $contentLocale = '',
        string $planLocale = '',
        array $languageCodes = []
    ): array {
        $description = \trim($description);
        if ($description === '') {
            return [
                'success' => false,
                'message' => (string)__('请先输入一句话需求'),
            ];
        }
        if (\mb_strlen($description, 'UTF-8') > 8000) {
            $description = (string)\mb_substr($description, 0, 8000, 'UTF-8');
        }

        $fallbackLocale = $this->resolveFallbackLocale();
        $contentLocale = $this->normalizePolishLocale($contentLocale, $fallbackLocale);
        $planLocale = $this->normalizePolishLocale($planLocale, $contentLocale);
        $languageCodes = $this->normalizePolishLocaleList($languageCodes, $contentLocale);

        if ($fakeMode) {
            return [
                'success' => true,
                'message' => (string)__('润色完成'),
                'description' => $this->buildFakeStructuredBrief($description, $contentLocale, $planLocale, $languageCodes),
                'content_locale' => $contentLocale,
                'plan_locale' => $planLocale,
                'language_codes' => $languageCodes,
            ];
        }

        $aiService = $this->getAiRuntime();
        if ($aiService === null) {
            return [
                'success' => false,
                'message' => (string)__('AI 服务不可用，无法润色需求'),
            ];
        }

        $instruction = $this->buildPolishInstruction($description, $contentLocale, $planLocale, $languageCodes);

        try {
            $polished = \trim((string)$aiService->generate(
                $instruction,
                null,
                self::PLAN_SCENARIO_CODE,
                $planLocale,
                [
                    'temperature' => 0.45,
                    // DeepSeek V4 thinking shares this budget with CoT; keep headroom for the brief.
                    'max_tokens' => 16384,
                    // Polish is a template rewrite — disable thinking so CoT cannot empty content.
                    'thinking' => ['type' => 'disabled'],
                    'reasoning_text_fallback' => true,
                    'reasoning_text_fallback_markers' => ['# Role & System Prompt'],
                    'disable_conversation_history' => true,
                    'disable_conversation_persist' => true,
                    'request_source' => 'site_builder_polish_description',
                ],
                null,
                true
            ));
            $polished = \preg_replace('/^```(?:markdown|md)?\s*/u', '', $polished) ?? $polished;
            $polished = \preg_replace('/\s*```$/u', '', $polished) ?? $polished;
            $polished = \preg_replace('/^["“]|["”]$/u', '', $polished) ?? $polished;
            $polished = \trim((string)$polished);
            if ($polished === '') {
                return [
                    'success' => false,
                    'message' => (string)__('润色失败：模型未返回内容（思考链可能占满输出额度，请重试）'),
                ];
            }

            return [
                'success' => true,
                'message' => (string)__('润色完成'),
                'description' => $polished,
                'content_locale' => $contentLocale,
                'plan_locale' => $planLocale,
                'language_codes' => $languageCodes,
            ];
        } catch (\Throwable $throwable) {
            return [
                'success' => false,
                'message' => (string)__('润色失败：%{1}', [$throwable->getMessage()]),
            ];
        }
    }

    /**
     * @param list<string> $languageCodes
     */
    private function buildPolishInstruction(
        string $description,
        string $contentLocale,
        string $planLocale,
        array $languageCodes = []
    ): string {
        $related = \array_values(\array_filter(
            $languageCodes,
            static fn (string $code): bool => $code !== $contentLocale
        ));
        $relatedText = $related === []
            ? 'none (single-language site)'
            : \implode(', ', $related);

        $languageRule = $contentLocale === $planLocale
            ? "Write the entire polished brief body in {$planLocale} (except the fixed English section titles below, brand names, and technical codes)."
            : "Write the polished brief body (operator/planning explanations) in {$planLocale}. Explicitly require all visitor-facing site copy (titles, body, buttons, SEO, alt text) to use {$contentLocale} as the default language. Do not mix these two languages incorrectly.";

        return "You are a world-class full-stack frontend engineer and UX design expert, and also a website-brief polishing assistant. Rewrite the user's website requirement into a high-fidelity, interactive, production-ready MVP landing/site specification.\n\nHard output format (must use these Markdown H1 headings in this exact order; keep the English titles unchanged; no preamble, no epilogue, no code fences, no \"here is the result\" wording):\n\n# Role & System Prompt\nIn second person, define the role and overall goal: deliver a high-fidelity interactive production site; preserve the user's industry, market, languages, and conversion goals; state tech/stack constraints suited to the site type (marketing/APK promo sites emphasize semantic HTML, responsive layout, clear conversion funnel, and SEO — do not rewrite into an unrelated product).\n\n# Project Context\nExpand with project name, positioning, visual style (include primary/accent hex colors), and target audience. Must explicitly state:\n- Website/content language (visitor-facing default): {$contentLocale}\n- Related languages (additional locales): {$relatedText}\n- Plan/brief language (operator-facing): {$planLocale}\nIf related languages exist, require language switcher / localized CTA support for those locales. Reasonable enrichment is allowed but must not contradict the user.\n\n# Architecture & Layout\nDescribe overall layout and 3–6 core sections/modules; each section uses a bold subheading + bullets covering hero, trust/content, download or conversion, SEO/localization, etc.\n\n# Interactive Requirements (核心交互逻辑)\nList 3–6 hard demoable interactions (tab/state switching, primary CTA, form/download feedback, toast/modal, theme or language switch, etc.), each with trigger and visible result. If related languages exist, include a language switcher interaction.\n\n# Code Quality & Constraints\nCover Self-Contained, Responsiveness, No Placeholders, Icons/asset constraints; forbid Lorem Ipsum and TODO; copy must be realistic for the product context. Reiterate visitor default copy language = {$contentLocale}; related languages = {$relatedText}.\n\nLanguage rules: {$languageRule}\nKeep user constraints (market, gameplay, safe download, local aesthetics, SEO, mobile, etc.); enrich executable detail; output only the structured body above.\n\nUser requirement:\n{$description}";
    }

    private function resolveFallbackLocale(): string
    {
        try {
            $lang = \trim((string)\Weline\Framework\App\State::getLang());
            if ($lang !== '') {
                return $this->normalizePolishLocale($lang, 'zh_Hans_CN');
            }
        } catch (\Throwable) {
        }

        return 'zh_Hans_CN';
    }

    private function normalizePolishLocale(string $locale, string $fallback): string
    {
        $locale = \trim(\str_replace('-', '_', $locale));
        if ($locale === '') {
            return $fallback;
        }
        if (!\preg_match('/^[A-Za-z]{2,3}(?:_[A-Za-z0-9]{2,8}){0,3}$/', $locale)) {
            return $fallback;
        }

        return $locale;
    }

    /**
     * @param list<mixed> $languageCodes
     * @return list<string>
     */
    private function normalizePolishLocaleList(array $languageCodes, string $contentLocale): array
    {
        $values = [];
        foreach ($languageCodes as $item) {
            if (!\is_scalar($item)) {
                continue;
            }
            $code = $this->normalizePolishLocale((string)$item, '');
            if ($code === '' || \in_array($code, $values, true)) {
                continue;
            }
            $values[] = $code;
        }
        if ($contentLocale !== '' && !\in_array($contentLocale, $values, true)) {
            \array_unshift($values, $contentLocale);
        }

        return $values;
    }

    private function isEnglishPlanLocale(string $planLocale): bool
    {
        return \str_starts_with(\strtolower($planLocale), 'en');
    }

    /**
     * Deterministic site plan for fake_mode / offline tests (no live AI stream).
     *
     * @param list<string> $references
     * @return array<string, mixed>
     */
    private function buildFakePlan(
        string $brief,
        array $references,
        string $userMessage,
        ?callable $emit
    ): array {
        if ($emit !== null) {
            $emit('chunk', [
                'chunk' => (string)__('Local demo mode: building a deterministic site plan...'),
                'message' => (string)__('Local demo mode: building a deterministic site plan...'),
            ]);
        }

        $title = $this->pickString($userMessage, $brief, 'Demo Site');
        $title = \trim((string)\preg_replace('/\s+/u', ' ', $title));
        if (\mb_strlen($title) > 48) {
            $title = (string)\mb_substr($title, 0, 48);
        }
        if ($title === '') {
            $title = 'Demo Site';
        }
        $shortBrief = $brief !== '' ? $brief : $title;
        if (\mb_strlen($shortBrief) > 240) {
            $shortBrief = (string)\mb_substr($shortBrief, 0, 240) . '…';
        }

        return [
            'site_positioning' => (string)__('Local demo landing site for %{title}', ['title' => $title]),
            'brand_tone' => (string)__('Trustworthy, conversion-focused, modern'),
            'color_palette' => ['#0f172a', '#2563eb', '#10b981'],
            'visual_style' => (string)__('Clean cards, soft shadows, mobile-first'),
            'seo_keywords' => [$title, (string)__('official site'), (string)__('download')],
            'page_types' => ['home_page', 'about_page', 'contact_page', 'privacy_policy'],
            'build_mode' => 'static',
            'shared_elements' => ['header', 'footer'],
            'references_summary' => $references === []
                ? (string)__('No reference URLs provided.')
                : (string)__('References: %{list}', ['list' => \implode(', ', \array_slice($references, 0, 5))]),
            'domain_strategy' => (string)__('Prefer a short brandable domain; local .weline.test is fine for demo.'),
            'site_title' => $title,
            'site_tagline' => (string)__('Demo plan generated in local fake mode'),
            'brief_description' => $shortBrief,
        ];
    }

    private function isTruthyFlag(mixed $value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }
        if (\is_int($value) || \is_float($value)) {
            return (int)$value === 1;
        }
        if (\is_string($value)) {
            return \in_array(\strtolower(\trim($value)), ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }

    /**
     * Deterministic structured brief for fake_mode / offline tests.
     *
     * @param list<string> $languageCodes
     */
    private function buildFakeStructuredBrief(
        string $description,
        string $contentLocale = 'zh_Hans_CN',
        string $planLocale = 'zh_Hans_CN',
        array $languageCodes = []
    ): string {
        $title = \trim((string)\preg_replace('/\s+/u', ' ', $description));
        if (\mb_strlen($title, 'UTF-8') > 48) {
            $title = (string)\mb_substr($title, 0, 48, 'UTF-8') . '…';
        }
        $related = \array_values(\array_filter(
            $languageCodes,
            static fn (string $code): bool => $code !== $contentLocale
        ));
        $relatedText = $related === [] ? 'none' : \implode(', ', $related);

        if ($this->isEnglishPlanLocale($planLocale)) {
            return <<<MD
# Role & System Prompt
You are a world-class full-stack frontend engineer and UX design expert. Based on the requirement below, deliver a high-fidelity, interactive, production-ready MVP website/landing page: {$title}. Pages must be semantic, responsive, conversion-clear, and adapted to the target market aesthetics and SEO.

# Project Context
- **Project name**: {$title}
- **Positioning**: A high-conversion site for the target audience, highlighting core benefits, trust, and primary CTA.
- **Visual style**: Modern refined look; primary #0f172a, accents #2563eb / #10b981; rounded cards, soft shadows, micro-interactions.
- **Target audience**: Visitors matching the user brief (including mobile users).
- **Website/content language (visitor-facing default)**: {$contentLocale}
- **Related languages**: {$relatedText}
- **Plan/brief language (operator-facing)**: {$planLocale}

# Architecture & Layout
Use a top navigation + main single-page scroll (or a few inner pages). Core sections:
1. **Hero**: Value proposition, primary/secondary CTAs, trust chips.
2. **Features / gameplay**: 3–4 feature cards or screenshot wall.
3. **Trust & safety**: Reviews, certifications, privacy notes.
4. **Conversion**: Download/register primary button and supporting guidance.
5. **Footer**: Links, compliance, and contact.

# Interactive Requirements (核心交互逻辑)
- **Primary CTA**: Clicking download/register shows a success toast.
- **Anchor navigation**: Top-nav clicks smooth-scroll to sections.
- **Mobile menu**: Hamburger open/close on small screens.
- **Language switcher**: Switch between {$contentLocale} and related languages when configured.
- **Trust accordion**: FAQ/details expand and collapse.

# Code Quality & Constraints
- **Self-Contained**: Mock data must be embedded; no external backends that require API keys.
- **Responsiveness**: Full Desktop / Mobile support.
- **No Placeholders**: No Lorem Ipsum or TODO; copy must be realistic and usable.
- **Icons**: Use one consistent icon set.
- **Language**: Visitor-facing default copy must use {$contentLocale}; related languages: {$relatedText}.
MD;
        }

        return <<<MD
# Role & System Prompt
你是一位世界顶级的前端全栈工程师与 UX 设计专家。请基于下列需求，交付一个高保真、可交互的生产级 MVP 官网/落地页：{$title}。页面需语义化、响应式，转化路径清晰，并适配目标市场审美与 SEO。

# Project Context
- **项目名称**: {$title}
- **定位**: 面向目标用户的高转化官网，突出核心卖点、信任背书与主 CTA。
- **视觉风格**: 现代精致风；主色 #0f172a，点缀色 #2563eb / #10b981；卡片圆角、轻阴影与微动效。
- **目标受众**: 与用户需求一致的目标市场访客（含移动端用户）。
- **网站内容语言（访客默认）**: {$contentLocale}
- **关联语言**: {$relatedText}
- **方案/润色语言（运营侧）**: {$planLocale}

# Architecture & Layout
采用顶栏导航 + 主内容单页滚动（或少量内页）结构，核心板块包括：
1. **Hero 首屏**: 价值主张、主副 CTA、信任短标签。
2. **卖点/玩法展示**: 3～4 个特性卡片或截图墙。
3. **信任与安全**: 评价、认证、隐私说明。
4. **转化区**: 下载/注册主按钮与辅助引导。
5. **页脚**: 链接、合规与联系方式。

# Interactive Requirements (核心交互逻辑)
- **主 CTA**: 点击下载/注册按钮出现成功反馈 Toast。
- **锚点导航**: 顶栏点击平滑滚动到对应板块。
- **移动端菜单**: 小屏展开/收起汉堡菜单。
- **语言切换**: 在 {$contentLocale} 与关联语言之间切换（若已配置）。
- **信任折叠**: FAQ 或说明区可展开。

# Code Quality & Constraints
- **Self-Contained**: Mock 数据内置，不依赖需 API Key 的外部后端。
- **Responsiveness**: Desktop / Mobile 完整适配。
- **No Placeholders**: 禁止 Lorem Ipsum 与 TODO；文案真实可用。
- **Icons**: 使用统一图标集，风格一致。
- **Language**: 访客默认文案必须使用 {$contentLocale}；关联语言：{$relatedText}。
MD;
    }

    /**
     * @param list<string> $references
     * @param list<array{role:string,content:string}> $conversation
     * @param array<string, mixed> $currentPlan
     * @return array<string, mixed>
     */
    private function streamAiPlan(
        string $brief,
        array $references,
        array $conversation,
        array $currentPlan,
        string $userMessage,
        ?callable $emit
    ): array {
        $aiService = $this->getAiRuntime();
        if ($aiService === null) {
            throw new \RuntimeException((string)__('AI service is not available for plan generation'));
        }
        $fullContent = '';
        $prompt = $this->buildPrompt($brief, $references, $conversation, $currentPlan, $userMessage);

        try {
            $aiService->generateStream(
                $prompt,
                function (string $chunk) use (&$fullContent, $emit): void {
                    $fullContent .= $chunk;
                    if ($emit !== null) {
                        $emit('chunk', [
                            'chunk' => $chunk,
                            'message' => $chunk,
                        ]);
                    }
                },
                null,
                self::PLAN_SCENARIO_CODE,
                'zh_Hans_CN',
                [
                    'temperature' => 0.3,
                    'max_tokens' => 4000,
                    'response_format' => ['type' => 'json_object'],
                    'disable_conversation_history' => true,
                    'disable_conversation_persist' => true,
                    'request_source' => 'site_builder_plan_draft',
                ]
            );
        } catch (\Throwable $throwable) {
            $message = (string)__('AI plan generation failed: %{message}', ['message' => $throwable->getMessage()]);
            if ($emit !== null) {
                $emit('error', [
                    'message' => $message,
                ]);
            }
            throw new \RuntimeException($message, 0, $throwable);
        }

        $decoded = $this->extractFirstJsonObject($fullContent);
        if ($decoded === []) {
            $message = (string)__('AI plan generation failed: model did not return a valid JSON plan');
            if ($emit !== null) {
                $emit('error', [
                    'message' => $message,
                ]);
            }
            throw new \RuntimeException($message);
        }

        return $decoded;
    }

    private function getAiRuntime(): ?AiRuntimeInterface
    {
        if (!$this->aiRuntimeResolved) {
            $resolved = $this->runtimeProviderResolver->resolve(AiRuntimeInterface::class);
            $this->aiRuntime = $resolved instanceof AiRuntimeInterface ? $resolved : null;
            $this->aiRuntimeResolved = true;
        }

        return $this->aiRuntime;
    }

    /**
     * @param list<string> $references
     * @param list<array{role:string,content:string}> $conversation
     * @param array<string, mixed> $currentPlan
     */
    private function buildPrompt(
        string $brief,
        array $references,
        array $conversation,
        array $currentPlan,
        string $userMessage
    ): string {
        $referenceText = $references === [] ? 'None' : \implode("\n- ", \array_merge([''], $references));
        $conversationLines = [];
        foreach ($conversation as $message) {
            $role = \trim((string)($message['role'] ?? 'user'));
            $content = \trim((string)($message['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $conversationLines[] = '[' . $role . '] ' . $content;
        }
        $conversationText = $conversationLines === [] ? 'None' : \implode("\n", $conversationLines);
        $currentPlanText = $currentPlan === []
            ? 'None'
            : (\json_encode($currentPlan, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) ?: 'None');

        return \implode("\n", [
            'You are an AI site planning assistant.',
            'Return STRICT JSON only. No markdown fences, no explanations.',
            'Build a practical site plan.',
            'The plan must contain these keys:',
            '{',
            '  "site_positioning": "string",',
            '  "brand_tone": "string",',
            '  "color_palette": ["string", "string", "string"],',
            '  "visual_style": "string",',
            '  "seo_keywords": ["string", "string", "string"],',
            '  "page_types": ["home_page", "about_page", "contact_page", "custom_page", "blog_list"],',
            '  "build_mode": "string",',
            '  "shared_elements": ["header", "footer"],',
            '  "references_summary": "string",',
            '  "domain_strategy": "string",',
            '  "site_title": "string",',
            '  "site_tagline": "string",',
            '  "brief_description": "string"',
            '}',
            'Rules:',
            '- page_types must use only these page type codes: home_page, about_page, contact_page, privacy_policy, terms_of_service, refund_policy, shipping_policy, cookie_policy, blog_post, blog_category, blog_list, custom_page.',
            '- If the user explicitly asks for product, service, solution, case, portfolio, or series pages and no dedicated code exists, include custom_page.',
            '- If several requested pages collapse into custom_page, preserve their exact page intents and labels inside brief_description.',
            '- If the user explicitly asks for academy, knowledge, articles, news, resources, guides, or blog pages, include blog_list.',
            '- SEO keywords should be realistic and user-facing.',
            '- references_summary must explain how the references affect the plan.',
            '- Keep site_title concise and site_tagline short.',
            '- color_palette must be derived from the brief. Do not fall back to generic blue unless the brief actually asks for it.',
            '- visual_style must name concrete visual anchors: layout rhythm, imagery type, texture/material, CTA treatment, and atmosphere. Avoid generic words only.',
            '- brief_description must be a compact generation contract that preserves requested pages, visual direction, conversion goals, and content language.',
            '- Use the same customer language as the original brief for all customer-visible values. For a Chinese brief, use Simplified Chinese except brand names and technical codes.',
            'Original brief:',
            $brief !== '' ? $brief : 'None',
            'Latest user message:',
            $userMessage !== '' ? $userMessage : 'None',
            'Reference URLs or image URLs:',
            $referenceText,
            'Conversation history:',
            $conversationText,
            'Current plan JSON:',
            $currentPlanText,
        ]);
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function buildPlanMarkdown(array $plan): string
    {
        $colorPalette = $this->normalizeColorPalette($plan['color_palette'] ?? []);
        $seoKeywords = $this->normalizeStringList($plan['seo_keywords'] ?? []);
        $pageTypes = $this->normalizeStringList($plan['page_types'] ?? []);

        return \implode("\n", [
            '# 建站方案',
            '',
            '## 定位',
            '- 站点定位：' . (string)($plan['site_positioning'] ?? ''),
            '- 品牌语气：' . (string)($plan['brand_tone'] ?? ''),
            '- 视觉风格：' . (string)($plan['visual_style'] ?? ''),
            '- 构建模式：' . (string)($plan['build_mode'] ?? ''),
            '',
            '## 品牌',
            '- 站点标题：' . (string)($plan['site_title'] ?? ''),
            '- 标语：' . (string)($plan['site_tagline'] ?? ''),
            '- 配色：' . ($colorPalette === [] ? '-' : \implode(' / ', $colorPalette)),
            '',
            '## SEO',
            '- 关键词：' . ($seoKeywords === [] ? '-' : \implode('、', $seoKeywords)),
            '',
            '## 页面',
            '- 页面类型：' . ($pageTypes === [] ? '-' : \implode('、', $pageTypes)),
            '- 共享元素：header、footer',
            '',
            '## 参考摘要',
            (string)($plan['references_summary'] ?? ''),
            '',
            '## 域名策略',
            (string)($plan['domain_strategy'] ?? ''),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function extractFirstJsonObject(string $content): array
    {
        $content = \trim($content);
        if ($content === '') {
            return [];
        }

        try {
            $decoded = \json_decode($content, true, 512, \JSON_THROW_ON_ERROR);
            return \is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
        }

        $start = \strpos($content, '{');
        $end = \strrpos($content, '}');
        if ($start === false || $end === false || $end <= $start) {
            return [];
        }

        $json = \substr($content, $start, $end - $start + 1);
        if (!\is_string($json) || \trim($json) === '') {
            return [];
        }

        try {
            $decoded = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
            return \is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<string>
     */
    private function normalizeReferenceList(mixed $raw): array
    {
        if (\is_array($raw)) {
            $items = $raw;
        } elseif (\is_string($raw) && \trim($raw) !== '') {
            $items = \preg_split('/[\r\n,]+/', $raw, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        } else {
            $items = [];
        }

        $result = [];
        foreach ($items as $item) {
            if (!\is_scalar($item)) {
                continue;
            }
            $value = \trim((string)$item);
            if ($value === '' || \in_array($value, $result, true)) {
                continue;
            }
            $result[] = $value;
        }

        return $result;
    }

    /**
     * @return list<array{role:string,content:string}>
     */
    private function normalizeConversation(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $result = [];
        foreach ($raw as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $role = \trim((string)($item['role'] ?? 'user'));
            $content = \trim((string)($item['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $result[] = [
                'role' => $role !== '' ? $role : 'user',
                'content' => $content,
            ];
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $raw): array
    {
        if (\is_array($raw)) {
            $items = $raw;
        } elseif (\is_string($raw) && \trim($raw) !== '') {
            $items = \preg_split('/[\r\n,]+/', $raw, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        } else {
            $items = [];
        }

        $result = [];
        foreach ($items as $item) {
            if (!\is_scalar($item)) {
                continue;
            }
            $value = \trim((string)$item);
            if ($value === '' || \in_array($value, $result, true)) {
                continue;
            }
            $result[] = $value;
        }

        return $result;
    }

    /**
     * @return list<string>
     */
    private function normalizeColorPalette(mixed $raw): array
    {
        return \array_slice($this->normalizeStringList($raw), 0, 6);
    }

    /**
     * @param array<string, mixed> $currentPlan
     * @return list<string>
     */
    private function inferPageTypes(string $seedText, array $currentPlan): array
    {
        $pageTypes = $this->filterSupportedPageTypes($currentPlan['page_types'] ?? []);
        if ($pageTypes === []) {
            $pageTypes = ['home_page', 'about_page', 'contact_page'];
        }

        $lower = \strtolower($seedText);
        if (\str_contains($lower, 'blog') || \str_contains($seedText, '博客')) {
            $pageTypes[] = 'blog_list';
        }
        if (\str_contains($lower, 'policy') || \str_contains($seedText, '隐私')) {
            $pageTypes[] = 'privacy_policy';
        }

        if ($this->matchesAnyPattern($seedText, [
            '/blog|article|news|academy|learn|education|guide|insight|resource|journal/i',
            '/\x{5b66}\x{9662}|\x{77e5}\x{8bc6}|\x{6559}\x{7a0b}|\x{8d44}\x{8baf}|\x{6587}\x{7ae0}|\x{535a}\x{5ba2}|\x{8bfe}\x{5802}/u',
        ])) {
            $pageTypes[] = 'blog_list';
        }
        if ($this->matchesAnyPattern($seedText, [
            '/product|catalog|collection|service|solution|portfolio|case|menu|sku|story|experience|reservation|booking|appointment|order|shop|store|location/i',
            '/\x{4ea7}\x{54c1}|\x{7cfb}\x{5217}|\x{83dc}\x{5355}|\x{670d}\x{52a1}|\x{65b9}\x{6848}|\x{6848}\x{4f8b}|\x{9879}\x{76ee}|\x{6545}\x{4e8b}|\x{4f53}\x{9a8c}|\x{9884}\x{7ea6}|\x{8ba2}\x{8d2d}|\x{95e8}\x{5e97}/u',
        ])) {
            $pageTypes[] = 'custom_page';
        }
        if ($this->matchesAnyPattern($seedText, [
            '/privacy|policy/i',
            '/\x{9690}\x{79c1}/u',
        ])) {
            $pageTypes[] = 'privacy_policy';
        }
        if ($this->matchesAnyPattern($seedText, [
            '/terms|service agreement/i',
            '/\x{6761}\x{6b3e}|\x{670d}\x{52a1}\x{534f}\x{8bae}/u',
        ])) {
            $pageTypes[] = 'terms_of_service';
        }
        if ($this->matchesAnyPattern($seedText, [
            '/refund|return/i',
            '/\x{9000}\x{6b3e}|\x{9000}\x{8d27}/u',
        ])) {
            $pageTypes[] = 'refund_policy';
        }
        if ($this->matchesAnyPattern($seedText, [
            '/shipping|delivery|logistics/i',
            '/\x{914d}\x{9001}|\x{7269}\x{6d41}|\x{8fd0}\x{8f93}/u',
        ])) {
            $pageTypes[] = 'shipping_policy';
        }
        if ($this->matchesAnyPattern($seedText, ['/cookie/i'])) {
            $pageTypes[] = 'cookie_policy';
        }

        return $this->sortSupportedPageTypes($pageTypes);
    }

    /**
     * @param mixed $raw
     * @param array<string, mixed> $currentPlan
     * @return list<string>
     */
    private function normalizeGeneratedPageTypes(mixed $raw, string $brief, array $currentPlan, string $userMessage): array
    {
        $seedText = \trim($userMessage . "\n" . $brief . "\n" . (string)($currentPlan['brief_description'] ?? ''));
        $provided = $this->filterSupportedPageTypes($raw);
        if ($provided === []) {
            $provided = $this->filterSupportedPageTypes($currentPlan['page_types'] ?? []);
        }
        $inferred = $this->inferPageTypes($seedText, []);
        $merged = $provided !== [] ? \array_merge($provided, $inferred) : $inferred;

        return $this->sortSupportedPageTypes($merged);
    }

    /**
     * @return list<string>
     */
    private function filterSupportedPageTypes(mixed $raw): array
    {
        $items = $this->normalizeStringList($raw);
        $pageTypes = [];
        foreach ($items as $item) {
            if (!\in_array($item, self::SUPPORTED_PAGE_TYPES, true) || \in_array($item, $pageTypes, true)) {
                continue;
            }
            $pageTypes[] = $item;
        }

        return $pageTypes;
    }

    /**
     * @param list<string> $pageTypes
     * @return list<string>
     */
    private function sortSupportedPageTypes(array $pageTypes): array
    {
        $unique = [];
        foreach ($pageTypes as $pageType) {
            if (!\in_array($pageType, self::SUPPORTED_PAGE_TYPES, true) || \in_array($pageType, $unique, true)) {
                continue;
            }
            $unique[] = $pageType;
        }
        if (!\in_array('home_page', $unique, true)) {
            \array_unshift($unique, 'home_page');
        }

        $ordered = [];
        foreach (self::SUPPORTED_PAGE_TYPES as $supported) {
            if (\in_array($supported, $unique, true)) {
                $ordered[] = $supported;
            }
        }

        return $ordered;
    }

    /**
     * @param list<string> $patterns
     */
    private function matchesAnyPattern(string $text, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (@\preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    private function normalizeBuildMode(string $buildMode): string
    {
        $buildMode = \trim($buildMode);

        return \trim($buildMode);
    }

    private function pickString(mixed ...$values): string
    {
        foreach ($values as $value) {
            if (!\is_scalar($value)) {
                continue;
            }
            $string = \trim((string)$value);
            if ($string !== '') {
                return $string;
            }
        }

        return '';
    }
}

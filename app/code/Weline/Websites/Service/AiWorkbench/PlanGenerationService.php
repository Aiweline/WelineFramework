<?php

declare(strict_types=1);

namespace Weline\Websites\Service\AiWorkbench;

use Weline\Ai\Service\AiService;
use Weline\Framework\Manager\ObjectManager;

class PlanGenerationService
{
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

    public function __construct(
        private readonly ?AiService $aiService = null,
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

        if ($emit !== null) {
            $emit('status', [
                'message' => (string)__('Starting plan generation'),
            ]);
        }

        $generated = $this->streamAiPlan($brief, $references, $conversation, $currentPlan, $userMessage, $emit);
        if ($generated === []) {
            $generated = $this->buildFallbackPlan($brief, $references, $currentPlan, $userMessage);
        }

        $generated['build_mode'] = $this->normalizeBuildMode((string)($generated['build_mode'] ?? ''));
        $generated['page_types'] = $this->normalizeGeneratedPageTypes(
            $generated['page_types'] ?? [],
            $brief,
            $currentPlan,
            $userMessage
        );
        $generated['plan_markdown'] = $this->buildPlanMarkdown($generated);
        $generated['reference_urls'] = $references;
        $generated['updated_from_message'] = $userMessage;

        if ($emit !== null) {
            $emit('plan_completed', [
                'message' => (string)__('Plan generation completed'),
                'plan' => $generated,
            ]);
        }

        return $generated;
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
        if (!\class_exists(AiService::class)) {
            return [];
        }

        $aiService = $this->aiService ?? ObjectManager::getInstance(AiService::class);
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
                null,
                'zh_Hans_CN',
                ['temperature' => 0.3, 'max_tokens' => 4000]
            );
        } catch (\Throwable $throwable) {
            if ($emit !== null) {
                $emit('warning', [
                    'message' => (string)__('AI plan generation failed, fallback will be used: %{message}', ['message' => $throwable->getMessage()]),
                ]);
            }
            return [];
        }

        return $this->extractFirstJsonObject($fullContent);
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
            'You are an AI site planning assistant for a PageBuilder-driven site workbench.',
            'Return STRICT JSON only. No markdown fences, no explanations.',
            'Build a practical site plan that can be used directly for PageBuilder generation.',
            'The plan must contain these keys:',
            '{',
            '  "site_positioning": "string",',
            '  "brand_tone": "string",',
            '  "color_palette": ["string", "string", "string"],',
            '  "visual_style": "string",',
            '  "seo_keywords": ["string", "string", "string"],',
            '  "page_types": ["home_page", "about_page", "contact_page", "custom_page", "blog_list"],',
            '  "build_mode": "pagebuilder_style or pagebuilder_html",',
            '  "shared_elements": ["header", "footer"],',
            '  "references_summary": "string",',
            '  "domain_strategy": "string",',
            '  "site_title": "string",',
            '  "site_tagline": "string",',
            '  "brief_description": "string"',
            '}',
            'Rules:',
            '- page_types must use only these PageBuilder page type codes: home_page, about_page, contact_page, privacy_policy, terms_of_service, refund_policy, shipping_policy, cookie_policy, blog_post, blog_category, blog_list, custom_page.',
            '- If the user explicitly asks for product, service, solution, case, portfolio, or series pages and no dedicated code exists, include custom_page.',
            '- If several requested pages collapse into custom_page, preserve their exact page intents and labels inside brief_description.',
            '- If the user explicitly asks for academy, knowledge, articles, news, resources, guides, or blog pages, include blog_list.',
            '- build_mode must be exactly one of pagebuilder_style or pagebuilder_html.',
            '- Prefer pagebuilder_style unless the request clearly asks for simple lightweight HTML pages.',
            '- SEO keywords should be realistic and user-facing.',
            '- references_summary must explain how the references affect the plan.',
            '- Keep site_title concise and site_tagline short.',
            '- color_palette must be derived from the brief. Do not fall back to generic blue unless the brief actually asks for it.',
            '- visual_style must name concrete visual anchors: layout rhythm, imagery type, texture/material, CTA treatment, and atmosphere. Avoid generic words only.',
            '- brief_description must be a compact generation contract that preserves requested pages, visual direction, conversion goals, and content language.',
            '- Use the same customer language as the original brief for all customer-visible values. For a Chinese brief, use Simplified Chinese except brand names and PageBuilder codes.',
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
     * @param list<string> $references
     * @param array<string, mixed> $currentPlan
     * @return array<string, mixed>
     */
    private function buildFallbackPlan(string $brief, array $references, array $currentPlan, string $userMessage): array
    {
        $identitySeed = $this->sanitizeSeedText($this->pickString($brief, (string)($currentPlan['brief_description'] ?? ''), $userMessage));
        $revisionSeed = $this->sanitizeSeedText($userMessage);
        $planningSeed = $this->sanitizeSeedText(\trim($identitySeed . ' ' . $revisionSeed));
        $buildMode = $this->inferBuildMode($planningSeed, (string)($currentPlan['build_mode'] ?? ''));
        $pageTypes = $this->inferPageTypes($planningSeed, $currentPlan);
        $siteTitle = $this->inferSiteTitle($identitySeed, (string)($currentPlan['site_title'] ?? 'AI Site'));
        $siteTagline = $this->inferSiteTagline($planningSeed, (string)($currentPlan['site_tagline'] ?? ''));
        $keywords = $this->inferSeoKeywords($identitySeed, $siteTitle);
        $positioning = $this->inferSitePositioning($planningSeed, $siteTitle);
        $visualStyle = $this->inferVisualStyle($planningSeed, (string)($currentPlan['visual_style'] ?? ''));
        $colorPalette = $this->inferColorPalette($planningSeed, $currentPlan['color_palette'] ?? []);
        $brandTone = $this->inferBrandTone($planningSeed, (string)($currentPlan['brand_tone'] ?? ''));
        $generationContract = $this->buildFallbackBriefDescription($planningSeed, $siteTitle, $siteTagline, $visualStyle, $pageTypes);
        $referencesSummary = $references === []
            ? $this->unicodeText('\u672a\u63d0\u4f9b\u5916\u90e8\u53c2\u8003\uff0c\u65b9\u6848\u57fa\u4e8e\u6587\u5b57\u9700\u6c42\u751f\u6210\u3002')
            : $this->unicodeText('\u53c2\u8003\u7d20\u6750\u4f1a\u7ea6\u675f\u89c6\u89c9\u8c03\u6027\u3001\u914d\u8272\u548c\u8f6c\u5316\u65b9\u5411\uff1a') . \implode(', ', $references);

        return [
            'site_positioning' => $positioning,
            'brand_tone' => $brandTone,
            'color_palette' => $colorPalette,
            'visual_style' => $visualStyle,
            'seo_keywords' => $keywords,
            'page_types' => $pageTypes,
            'build_mode' => $buildMode,
            'shared_elements' => ['header', 'footer'],
            'references_summary' => $referencesSummary,
            'domain_strategy' => (string)($currentPlan['domain_strategy'] ?? $this->unicodeText('\u4f18\u5148\u9009\u62e9\u7b80\u77ed\u3001\u6613\u8bb0\u3001\u8d34\u5408\u54c1\u724c\u7684\u57df\u540d\u3002')),
            'site_title' => $siteTitle,
            'site_tagline' => $siteTagline,
            'brief_description' => $generationContract,
        ];
    }

    private function sanitizeSeedText(string $seedText): string
    {
        $seedText = \trim($seedText);
        if ($seedText === '') {
            return '';
        }

        $seedText = (string)\preg_replace('/\b([A-Z])\1([a-z]{2,})\b/', '$1$2', $seedText);
        $seedText = (string)\preg_replace('/\s+/', ' ', $seedText);

        return \trim($seedText);
    }

    private function inferSitePositioning(string $seedText, string $siteTitle): string
    {
        if ($this->matchesAnyPattern($seedText, [
            '/matcha|tea|kyoto|japanese|dessert|pastry|bakery/i',
            '/\x{62b9}\x{8336}|\x{8336}\x{996e}|\x{751c}\x{54c1}|\x{4eac}\x{90fd}|\x{65e5}\x{5f0f}/u',
        ])) {
            return $this->localText(
                $seedText,
                '\u9762\u5411\u6ce8\u91cd\u4eea\u5f0f\u611f\u548c\u54c1\u8d28\u7684\u8336\u996e\u4e0e\u751c\u54c1\u7528\u6237\uff0c\u7a81\u51fa\u624b\u4f5c\u62b9\u8336\u3001\u4f4e\u7cd6\u4ea7\u54c1\u3001\u95e8\u5e97\u4f53\u9a8c\u548c\u9884\u7ea6\u8f6c\u5316\u3002',
                'Premium tea and dessert brand site focused on craft matcha, low-sugar products, store experience, and reservation/order conversion.'
            );
        }

        return $this->clipText($seedText !== '' ? $seedText : $siteTitle, 140);
    }

    private function inferBrandTone(string $seedText, string $currentTone): string
    {
        if ($this->matchesAnyPattern($seedText, ['/premium|luxury|refined|high[- ]end|boutique/i', '/\x{9ad8}\x{7aef}|\x{7cbe}\x{81f4}|\x{5962}\x{534e}|\x{9ad8}\x{7ea7}/u'])) {
            return $this->localText(
                $seedText,
                '\u514b\u5236\u3001\u9ad8\u7ea7\u3001\u624b\u4f5c\u611f\u3001\u53ef\u4fe1\u3001\u5177\u6709\u4eea\u5f0f\u611f',
                'restrained, premium, craft-led, trustworthy, and ritual-focused'
            );
        }
        if ($currentTone !== '') {
            return $currentTone;
        }

        return $this->localText($seedText, '\u6e05\u6670\u3001\u5177\u4f53\u3001\u53ef\u4fe1\u3001\u6709\u8f6c\u5316\u5bfc\u5411', 'clear, specific, trustworthy, and conversion-focused');
    }

    /**
     * @return list<string>
     */
    private function inferColorPalette(string $seedText, mixed $currentPalette): array
    {
        if ($this->matchesAnyPattern($seedText, [
            '/matcha|tea|kyoto|japanese|dessert|pastry|bakery/i',
            '/\x{62b9}\x{8336}|\x{8336}\x{996e}|\x{751c}\x{54c1}|\x{4eac}\x{90fd}|\x{65e5}\x{5f0f}/u',
        ])) {
            return ['#4F6F3A', '#F6F0DE', '#1E1A16', '#C8A96A', '#DDE8C8'];
        }
        if ($this->matchesAnyPattern($seedText, ['/premium|luxury|jewelry|hotel|spa|boutique/i', '/\x{9ad8}\x{7aef}|\x{5962}\x{534e}|\x{73e0}\x{5b9d}|\x{9152}\x{5e97}/u'])) {
            return ['#111111', '#F7F1E8', '#B88A2D', '#5C4632'];
        }
        if ($this->matchesAnyPattern($seedText, ['/outdoor|garden|nature|pergola|landscape/i', '/\x{6237}\x{5916}|\x{82b1}\x{56ed}|\x{5ead}\x{9662}|\x{81ea}\x{7136}/u'])) {
            return ['#2F5132', '#EEE7D5', '#8B6F47', '#D7A451'];
        }
        if ($this->matchesAnyPattern($seedText, ['/saas|software|ai|cloud|developer|platform/i', '/\x{79d1}\x{6280}|\x{5e73}\x{53f0}|\x{8f6f}\x{4ef6}|\x{4eba}\x{5de5}\x{667a}\x{80fd}/u'])) {
            return ['#0B1220', '#38BDF8', '#E0F2FE', '#A3E635'];
        }

        $colors = $this->normalizeColorPalette($currentPalette);
        if (!$this->isGenericBluePalette($colors)) {
            return $colors;
        }

        return ['#111827', '#F4EFE6', '#B7791F', '#E5E7EB'];
    }

    private function inferVisualStyle(string $seedText, string $currentStyle): string
    {
        if ($this->matchesAnyPattern($seedText, [
            '/matcha|tea|kyoto|japanese|dessert|pastry|bakery/i',
            '/\x{62b9}\x{8336}|\x{8336}\x{996e}|\x{751c}\x{54c1}|\x{4eac}\x{90fd}|\x{65e5}\x{5f0f}/u',
        ])) {
            return $this->localText(
                $seedText,
                '\u65e5\u5f0f\u9ad8\u7aef\u8336\u996e\u89c6\u89c9\uff1a\u5927\u5e45\u9996\u5c4f\u4ea7\u54c1\u7279\u5199\u3001\u6696\u7c73\u767d\u7559\u767d\u3001\u62b9\u8336\u7eff\u5c42\u6b21\u3001\u70ad\u9ed1\u5bfc\u822a\u548c\u91d1\u7b94\u7ec6\u8282\uff0c\u7528\u9519\u843d\u7f16\u8f91\u5361\u7247\u4e32\u8054\u4ea7\u54c1\u3001\u54c1\u724c\u6545\u4e8b\u3001\u95e8\u5e97\u4f53\u9a8c\u548c\u9884\u7ea6 CTA\u3002',
                'Premium Japanese tea visual system with cinematic product close-ups, warm ivory whitespace, layered matcha greens, charcoal navigation, gold-leaf details, staggered editorial cards, and reservation/order CTAs.'
            );
        }
        if ($currentStyle !== '' && !$this->isGenericVisualStyle($currentStyle)) {
            return $currentStyle;
        }

        return $this->localText(
            $seedText,
            '\u7cbe\u51c6\u54c1\u724c\u89c6\u89c9\uff1a\u9996\u5c4f\u6709\u660e\u786e\u573a\u666f\u548c CTA\uff0c\u5185\u5bb9\u533a\u5757\u6709\u5c42\u6b21\u3001\u56fe\u50cf\u98ce\u683c\u3001\u7559\u767d\u548c\u8f6c\u5316\u8282\u594f\uff0c\u907f\u514d\u901a\u7528\u5361\u7247\u6a21\u677f\u611f\u3002',
            'Precise brand visual system with a scene-led hero, layered content blocks, clear imagery direction, whitespace, conversion rhythm, and no generic card-template feel.'
        );
    }

    private function buildFallbackBriefDescription(string $seedText, string $siteTitle, string $siteTagline, string $visualStyle, array $pageTypes): string
    {
        $requestedPages = $this->extractRequestedPageSummary($seedText);
        $pageTypeText = \implode(', ', $pageTypes);
        if ($this->prefersChineseOutput($seedText)) {
            $parts = [
                '\u7ad9\u70b9\u540d\u79f0\uff1a' . $siteTitle . '\u3002',
                '\u4e00\u53e5\u8bdd\u5b9a\u4f4d\uff1a' . $siteTagline . '\u3002',
                '\u89c6\u89c9\u5951\u7ea6\uff1a' . $visualStyle,
                '\u8f6c\u5316\u76ee\u6807\uff1a\u9996\u5c4f\u548c\u5173\u952e\u533a\u5757\u5fc5\u987b\u5f15\u5bfc\u9884\u7ea6\u3001\u8ba2\u8d2d\u6216\u8054\u7cfb\u3002',
                '\u9875\u9762\u4ee3\u7801\uff1a' . $pageTypeText . '\u3002',
            ];
            if ($requestedPages !== '') {
                $parts[] = '\u7528\u6237\u8bf7\u6c42\u7684\u9875\u9762\u610f\u56fe\uff1a' . $requestedPages . '\u3002';
            }
            $parts[] = '\u5185\u5bb9\u8bed\u8a00\uff1a\u7b80\u4f53\u4e2d\u6587\uff0c\u54c1\u724c\u540d\u4fdd\u7559\u539f\u6587\u3002';

            return $this->clipText(\implode(' ', \array_map(fn (string $part): string => $this->unicodeText($part), $parts)), 520);
        }

        $parts = [
            'Site title: ' . $siteTitle . '.',
            'Positioning: ' . $siteTagline . '.',
            'Visual contract: ' . $visualStyle,
            'Conversion goal: hero and key sections must drive reservation, ordering, or contact.',
            'Page type codes: ' . $pageTypeText . '.',
        ];
        if ($requestedPages !== '') {
            $parts[] = 'Requested page intents: ' . $requestedPages . '.';
        }
        $parts[] = 'Content language follows the user brief.';

        return $this->clipText(\implode(' ', $parts), 520);
    }

    private function extractRequestedPageSummary(string $seedText): string
    {
        if (\preg_match('/(?:required|requested|need|needs|include|includes)\s+pages?\s+(.+?)(?:\s+Chinese\s+copy|\s+Chinese\s+content|$)/i', $seedText, $match) === 1) {
            return $this->clipText(\trim((string)$match[1]), 180);
        }
        if (\preg_match('/\x{9700}\x{8981}.*?\x{9875}\x{9762}(.{1,120})/u', $seedText, $match) === 1) {
            return $this->clipText(\trim((string)$match[1]), 180);
        }

        return '';
    }

    private function localText(string $seedText, string $zhEscaped, string $en): string
    {
        return $this->prefersChineseOutput($seedText) ? $this->unicodeText($zhEscaped) : $en;
    }

    private function prefersChineseOutput(string $seedText): bool
    {
        return $this->matchesAnyPattern($seedText, [
            '/Chinese\s+(copy|content|language)|Simplified\s+Chinese/i',
            '/\x{4e2d}\x{6587}|\x{7b80}\x{4f53}\x{4e2d}\x{6587}/u',
            '/[\x{4e00}-\x{9fff}]/u',
        ]);
    }

    /**
     * @param list<string> $colors
     */
    private function isGenericBluePalette(array $colors): bool
    {
        $joined = \strtolower(\implode(',', $colors));

        return $joined === '' || \str_contains($joined, '#2563eb');
    }

    private function isGenericVisualStyle(string $visualStyle): bool
    {
        $style = \trim($visualStyle);
        if ($style === '') {
            return true;
        }

        return \in_array($style, [
            $this->unicodeText('\u9ad8\u7aef\u4ea7\u54c1\u8425\u9500\u89c6\u89c9'),
            'High-end product marketing visual',
            'Premium product marketing visual',
        ], true);
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
        $colors = $this->normalizeStringList($raw);
        if ($colors === []) {
            return ['#0f172a', '#2563eb', '#f8fafc'];
        }

        return \array_slice($colors, 0, 6);
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

    private function unicodeText(string $jsonEscaped): string
    {
        $decoded = \json_decode('"' . $jsonEscaped . '"');

        return \is_string($decoded) ? $decoded : $jsonEscaped;
    }

    /**
     * @return list<string>
     */
    private function inferSeoKeywords(string $seedText, string $siteTitle): array
    {
        $words = [];
        foreach (\preg_split('/[\s,，。；;、\-]+/u', $seedText, -1, \PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $token = \trim((string)$token);
            if ($token === '' || \mb_strlen($token) < 2 || \in_array($token, $words, true)) {
                continue;
            }
            $words[] = $token;
            if (\count($words) >= 5) {
                break;
            }
        }
        if ($siteTitle !== '' && !\in_array($siteTitle, $words, true)) {
            \array_unshift($words, $siteTitle);
        }

        return \array_slice($words, 0, 6);
    }

    private function inferBuildMode(string $seedText, string $currentMode = ''): string
    {
        $currentMode = \trim($currentMode);
        if (\in_array($currentMode, ['pagebuilder_style', 'pagebuilder_html'], true)) {
            return $this->normalizeBuildMode($currentMode);
        }

        $lower = \strtolower($seedText);
        if (
            \str_contains($lower, 'html')
            || \str_contains($lower, 'landing')
            || \str_contains($seedText, '轻量')
            || \str_contains($seedText, '简单页面')
        ) {
            return 'pagebuilder_html';
        }

        return 'pagebuilder_style';
    }

    private function normalizeBuildMode(string $buildMode): string
    {
        $buildMode = \trim($buildMode);

        return $buildMode === 'pagebuilder_html' ? 'pagebuilder_html' : 'pagebuilder_style';
    }

    private function inferSiteTitle(string $seedText, string $fallback): string
    {
        $seedText = $this->sanitizeSeedText($seedText);
        $fallback = $this->sanitizeSeedText($fallback);
        if ($this->isUsableFallbackTitle($fallback)) {
            return $fallback;
        }
        if ($seedText === '') {
            return 'AI Site';
        }
        if (\preg_match('/\b(?:named|called)\s+([A-Z][A-Za-z0-9&\' -]{1,60})(?=\s+(?:premium|luxury|high|brand|site|website|for|with|required|needs?|creates?|sells?)\b|$)/', $seedText, $match) === 1) {
            return $this->clipText(\trim((string)$match[1]), 32);
        }
        if (\preg_match('/^([A-Z][A-Za-z0-9&\']+(?:\s+[A-Z][A-Za-z0-9&\']+){0,3})(?=\s+(?:premium|luxury|high|Japanese|matcha|dessert|tea|brand|site|website|for|with|required|needs?|creates?|sells?)\b|$)/', $seedText, $match) === 1) {
            return $this->clipText(\trim((string)$match[1]), 32);
        }

        if ($fallback !== '' && $fallback !== 'AI Site') {
            return $fallback;
        }

        $seedText = \trim($seedText);
        if ($seedText === '') {
            return 'AI Site';
        }

        $parts = \preg_split('/[，。,.!！?？\r\n]+/u', $seedText, -1, \PREG_SPLIT_NO_EMPTY) ?: [];
        $title = \trim((string)($parts[0] ?? $seedText));

        return $this->clipText($title, 24);
    }

    private function inferSiteTagline(string $seedText, string $fallback): string
    {
        $fallback = \trim($fallback);
        if ($fallback !== '' && \mb_strlen($fallback) <= 80 && !$this->looksLikeRawBrief($fallback)) {
            return $fallback;
        }
        if ($this->matchesAnyPattern($seedText, [
            '/matcha|tea|kyoto|japanese|dessert|pastry|bakery/i',
            '/\x{62b9}\x{8336}|\x{8336}\x{996e}|\x{751c}\x{54c1}|\x{4eac}\x{90fd}|\x{65e5}\x{5f0f}/u',
        ])) {
            return $this->localText(
                $seedText,
                '\u4eac\u90fd\u624b\u4f5c\u62b9\u8336\u751c\u54c1\u4e0e\u8336\u996e\u9884\u7ea6\u4f53\u9a8c',
                'Kyoto craft matcha desserts and tea reservations'
            );
        }

        if ($fallback !== '') {
            return $fallback;
        }

        return $this->clipText($seedText !== '' ? $seedText : 'AI-assisted site generation', 48);
    }

    private function isUsableFallbackTitle(string $fallback): bool
    {
        if ($fallback === '' || $fallback === 'AI Site') {
            return false;
        }
        if (\mb_strlen($fallback) > 36 || $this->looksLikeRawBrief($fallback)) {
            return false;
        }

        return true;
    }

    private function looksLikeRawBrief(string $text): bool
    {
        return $this->matchesAnyPattern($text, [
            '/\b(premium|luxury|brand|site|website|required|pages|create|created|with|visual|look|regenerate|revise|update|fix)\b/i',
            '/\x{89c6}\x{89c9}|\x{9875}\x{9762}|\x{5efa}\x{7ad9}|\x{9700}\x{8981}/u',
        ]);
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

    private function clipText(string $text, int $limit): string
    {
        $text = \trim($text);
        if ($text === '') {
            return '';
        }
        if (\mb_strlen($text) <= $limit) {
            return $text;
        }

        return \rtrim(\mb_substr($text, 0, $limit - 1)) . '…';
    }
}

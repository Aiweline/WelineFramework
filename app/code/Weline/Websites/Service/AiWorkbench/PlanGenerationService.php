<?php

declare(strict_types=1);

namespace Weline\Websites\Service\AiWorkbench;

use Weline\Ai\Service\AiService;
use Weline\Framework\Manager\ObjectManager;

class PlanGenerationService
{
    private const PLAN_SCENARIO_CODE = 'pagebuilder_plan_generation';

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
            throw new \RuntimeException((string)__('AI service is not available for plan generation'));
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

        return $buildMode === 'pagebuilder_html' ? 'pagebuilder_html' : 'pagebuilder_style';
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

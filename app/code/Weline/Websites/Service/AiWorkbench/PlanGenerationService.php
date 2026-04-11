<?php

declare(strict_types=1);

namespace Weline\Websites\Service\AiWorkbench;

use Weline\Ai\Service\AiService;
use Weline\Framework\Manager\ObjectManager;

class PlanGenerationService
{
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
            $userMessage,
            $draftPayload['description'] ?? null,
            $draftPayload['initial_description'] ?? null
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
            '  "page_types": ["home_page", "about_page", "contact_page"],',
            '  "build_mode": "pagebuilder_style or pagebuilder_html",',
            '  "shared_elements": ["header", "footer"],',
            '  "references_summary": "string",',
            '  "domain_strategy": "string",',
            '  "site_title": "string",',
            '  "site_tagline": "string",',
            '  "brief_description": "string"',
            '}',
            'Rules:',
            '- page_types must use PageBuilder page type codes.',
            '- build_mode must be exactly one of pagebuilder_style or pagebuilder_html.',
            '- Prefer pagebuilder_style unless the request clearly asks for simple lightweight HTML pages.',
            '- SEO keywords should be realistic and user-facing.',
            '- references_summary must explain how the references affect the plan.',
            '- Keep site_title concise and site_tagline short.',
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
        $seedText = $this->pickString($userMessage, $brief, (string)($currentPlan['brief_description'] ?? ''));
        $buildMode = $this->inferBuildMode($seedText, (string)($currentPlan['build_mode'] ?? ''));
        $pageTypes = $this->inferPageTypes($seedText, $currentPlan);
        $siteTitle = $this->inferSiteTitle($seedText, (string)($currentPlan['site_title'] ?? 'AI Site'));
        $siteTagline = $this->inferSiteTagline($seedText, (string)($currentPlan['site_tagline'] ?? ''));
        $keywords = $this->inferSeoKeywords($seedText, $siteTitle);
        $positioning = $this->clipText($seedText !== '' ? $seedText : $siteTitle, 120);
        $referencesSummary = $references === []
            ? (string)__('No external references were provided; the plan is based on the written requirement.')
            : (string)__('The provided references should guide the visual tone, palette, and conversion direction: %{refs}', ['refs' => \implode(', ', $references)]);

        return [
            'site_positioning' => $positioning,
            'brand_tone' => (string)($currentPlan['brand_tone'] ?? 'Modern, clear, trustworthy'),
            'color_palette' => $this->normalizeColorPalette($currentPlan['color_palette'] ?? ['#0f172a', '#2563eb', '#f8fafc']),
            'visual_style' => (string)($currentPlan['visual_style'] ?? 'High-contrast modern product marketing'),
            'seo_keywords' => $keywords,
            'page_types' => $pageTypes,
            'build_mode' => $buildMode,
            'shared_elements' => ['header', 'footer'],
            'references_summary' => $referencesSummary,
            'domain_strategy' => (string)($currentPlan['domain_strategy'] ?? 'Prefer short, memorable, brand-aligned domains.'),
            'site_title' => $siteTitle,
            'site_tagline' => $siteTagline,
            'brief_description' => $this->clipText($seedText !== '' ? $seedText : $positioning, 220),
        ];
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
        $existing = $this->normalizeStringList($currentPlan['page_types'] ?? []);
        if ($existing !== []) {
            return $existing;
        }

        $pageTypes = ['home_page', 'about_page', 'contact_page'];
        $lower = \strtolower($seedText);
        if (\str_contains($lower, 'blog') || \str_contains($seedText, '博客')) {
            $pageTypes[] = 'blog_list';
        }
        if (\str_contains($lower, 'policy') || \str_contains($seedText, '隐私')) {
            $pageTypes[] = 'privacy_policy';
        }

        return $pageTypes;
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
        $currentMode = $this->normalizeBuildMode($currentMode);
        if ($currentMode !== '') {
            return $currentMode;
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
        if ($fallback !== '') {
            return $fallback;
        }

        return $this->clipText($seedText !== '' ? $seedText : 'AI-assisted site generation', 48);
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

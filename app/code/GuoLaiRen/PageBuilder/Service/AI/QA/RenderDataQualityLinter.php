<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\QA;

final class RenderDataQualityLinter
{
    /** @var array<string, true> */
    private const PLAN_JSON_PAGE_META_KEYS = [
        'layout' => true,
        'page_meta' => true,
        'meta' => true,
        'seo' => true,
        'route' => true,
        'route_path' => true,
        'path' => true,
        'assets' => true,
        'blocks' => true,
        'block_previews' => true,
        'sections' => true,
        'components' => true,
        'page_design_plan' => true,
        'primary_keywords' => true,
        'secondary_keywords' => true,
        'style_code' => true,
        'style_settings' => true,
        'design_tokens' => true,
        'theme_css_ref' => true,
        'navigation' => true,
        'menus' => true,
        'links' => true,
        'settings' => true,
        'preview_url' => true,
        'preview_full_url' => true,
        'visual_preview_url' => true,
        'visual_edit_url' => true,
        'virtual_preview_url' => true,
        'virtual_edit_url' => true,
        'section_refinements' => true,
        'ai_description' => true,
    ];

    /**
     * Validate only render-data structure. Copy, SEO, locale, style, and
     * aesthetic judgments are intentionally left to AI prompt quality and
     * product review instead of hardcoded gates.
     *
     * @param array<string, mixed> $renderDataContract
     * @return list<array<string, mixed>>
     */
    public function lint(array $renderDataContract): array
    {
        $payload = \is_array($renderDataContract['payload'] ?? null) ? $renderDataContract['payload'] : $renderDataContract;
        $planJson = \is_array($payload['plan_json'] ?? null) ? $payload['plan_json'] : [];
        $pages = \is_array($planJson['pages'] ?? null) ? $planJson['pages'] : [];
        if ($pages === []) {
            return [
                $this->finding('error', 'structure', 'structure.missing_plan_json_pages', 'Build render data has no plan_json page output.', 'payload.plan_json.pages'),
            ];
        }

        $findings = [];
        foreach ($pages as $pageType => $page) {
            if (!\is_array($page)) {
                $findings[] = $this->finding(
                    'error',
                    'structure',
                    'structure.invalid_plan_json_page',
                    'Plan JSON page must be an object.',
                    'payload.plan_json.pages.' . (string)$pageType
                );
                continue;
            }
            $blocks = $this->extractPlanJsonPageBlocks($page);
            if ($blocks === []) {
                $findings[] = $this->finding(
                    'error',
                    'structure',
                    'structure.empty_plan_json_page',
                    'Plan JSON page has no generated blocks.',
                    'payload.plan_json.pages.' . (string)$pageType
                );
                continue;
            }
            foreach ($blocks as $blockKey => $block) {
                if (!\is_array($block)) {
                    $findings[] = $this->finding(
                        'error',
                        'structure',
                        'structure.invalid_plan_json_block',
                        'Plan JSON block must be an object.',
                        'payload.plan_json.pages.' . (string)$pageType . '.' . (string)$blockKey
                    );
                    continue;
                }
                $path = 'payload.plan_json.pages.' . (string)$pageType . '.' . (string)$blockKey;
                if ($this->firstNonEmptyString([(string)$blockKey, $block['block_key'] ?? null, $block['section_code'] ?? null, $block['code'] ?? null]) === '') {
                    $findings[] = $this->finding(
                        'error',
                        'structure',
                        'structure.missing_block_identity',
                        'Plan JSON block is missing a block identity.',
                        $path
                    );
                }
                if ($this->firstNonEmptyString([$block['html'] ?? null, $block['html_content'] ?? null, $block['phtml'] ?? null]) === '') {
                    $findings[] = $this->finding(
                        'error',
                        'structure',
                        'structure.missing_block_artifact',
                        'Plan JSON block is missing generated HTML/PHTML.',
                        $path
                    );
                }
                $htmlStructureReason = $this->detectBlockMalformedHtmlStructureReason($block);
                if ($htmlStructureReason !== null) {
                    $findings[] = $this->finding(
                        'error',
                        'structure',
                        'structure.malformed_html',
                        'Plan JSON block HTML has malformed tags or unbalanced structure: ' . $htmlStructureReason,
                        $path
                    );
                }
            }
        }

        return $findings;
    }

    /**
     * @param array<string, mixed> $page
     * @return array<string, mixed>
     */
    private function extractPlanJsonPageBlocks(array $page): array
    {
        $blocks = [];
        foreach ($page as $key => $value) {
            if (!\is_string($key) || !\is_array($value)) {
                continue;
            }
            if (isset(self::PLAN_JSON_PAGE_META_KEYS[$key])) {
                continue;
            }
            if (!$this->isPlanJsonBlockNode($value)) {
                continue;
            }
            $blocks[$key] = $value;
        }

        return $blocks;
    }

    /**
     * @param array<string, mixed> $node
     */
    private function isPlanJsonBlockNode(array $node): bool
    {
        return $this->firstNonEmptyString([$node['block_key'] ?? null, $node['section_code'] ?? null, $node['code'] ?? null]) !== ''
            || \array_key_exists('html', $node)
            || \array_key_exists('html_content', $node)
            || \array_key_exists('phtml', $node)
            || \array_key_exists('status', $node);
    }

    /**
     * @param array<string, mixed> $block
     */
    private function detectBlockMalformedHtmlStructureReason(array $block): ?string
    {
        foreach ($this->extractBlockHtmlFields($block) as $html) {
            $reason = $this->detectMalformedHtmlStructureReason($html);
            if ($reason !== null) {
                return $reason;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $block
     * @return list<string>
     */
    private function extractBlockHtmlFields(array $block): array
    {
        $fields = [];
        foreach (['html', 'html_content', 'html_extra', 'html_extra_column'] as $key) {
            if (\is_string($block[$key] ?? null) && \trim((string)$block[$key]) !== '') {
                $fields[] = (string)$block[$key];
            }
        }
        $config = \is_array($block['config'] ?? null) ? $block['config'] : [];
        foreach (['html', 'html_content', 'html_extra', 'html_extra_column'] as $key) {
            if (\is_string($config[$key] ?? null) && \trim((string)$config[$key]) !== '') {
                $fields[] = (string)$config[$key];
            }
        }

        return $fields;
    }

    private function detectMalformedHtmlStructureReason(string $html): ?string
    {
        $html = \trim($html);
        if ($html === '') {
            return null;
        }
        $html = \preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $html) ?? $html;
        $html = \preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $html) ?? $html;

        $voidTags = \array_fill_keys([
            'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input',
            'link', 'meta', 'param', 'source', 'track', 'wbr',
        ], true);
        $tagCount = \preg_match_all('/<\s*\/?\s*([a-z][a-z0-9:-]*)\b[^>]*(?:>|$)/iu', $html, $matches, \PREG_SET_ORDER);
        if ($tagCount === false || $tagCount === 0) {
            return null;
        }

        $stack = [];
        foreach ($matches as $match) {
            $tagText = (string)($match[0] ?? '');
            $tagName = \strtolower((string)($match[1] ?? ''));
            if ($tagName === '') {
                continue;
            }
            $tagReason = $this->detectMalformedTagTokenReason($tagText);
            if ($tagReason !== null) {
                return $tagReason . ' near <' . $tagName . '>';
            }
            if (\preg_match('/^<\s*\/\s*/', $tagText) === 1) {
                $last = \array_pop($stack);
                if ($last === null) {
                    return 'orphan closing tag </' . $tagName . '>';
                }
                if ($last !== $tagName) {
                    return 'crossed closing tag </' . $tagName . '> while <' . $last . '> is still open';
                }
                continue;
            }
            if (isset($voidTags[$tagName]) || \preg_match('/\/\s*>$/', $tagText) === 1) {
                continue;
            }
            $stack[] = $tagName;
        }

        if ($stack !== []) {
            return 'unclosed tag <' . (string)\end($stack) . '>';
        }

        return null;
    }

    private function detectMalformedTagTokenReason(string $tagText): ?string
    {
        $tagText = \trim($tagText);
        if ($tagText === '') {
            return null;
        }
        if (!\str_ends_with($tagText, '>')) {
            return 'unterminated tag';
        }

        $quote = '';
        $length = \strlen($tagText);
        for ($index = 1; $index < $length - 1; $index++) {
            $char = $tagText[$index];
            if ($quote !== '') {
                if ($char === $quote && ($index === 0 || $tagText[$index - 1] !== '\\')) {
                    $quote = '';
                }
                continue;
            }
            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }
            if ($char === '<') {
                return 'nested tag marker inside an opening tag';
            }
        }
        if ($quote !== '') {
            return 'unclosed attribute quote';
        }

        return null;
    }

    /**
     * @param list<mixed> $candidates
     */
    private function firstNonEmptyString(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            if (\is_scalar($candidate)) {
                $value = \trim((string)$candidate);
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function finding(string $severity, string $category, string $rule, string $message, string $targetPath): array
    {
        return [
            'severity' => $severity,
            'category' => $category,
            'rule' => $rule,
            'message' => $message,
            'target_path' => $targetPath,
        ];
    }
}

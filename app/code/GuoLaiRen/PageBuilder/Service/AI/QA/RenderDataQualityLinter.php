<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\QA;

final class RenderDataQualityLinter
{
    /**
     * @param array<string, mixed> $renderDataContract
     * @return list<array<string, mixed>>
     */
    public function lint(array $renderDataContract): array
    {
        $payload = \is_array($renderDataContract['payload'] ?? null) ? $renderDataContract['payload'] : $renderDataContract;
        $findings = [];

        foreach ($this->lintDesign($payload) as $finding) {
            $findings[] = $finding;
        }
        foreach ($this->lintCopy($payload) as $finding) {
            $findings[] = $finding;
        }
        foreach ($this->lintSeo($payload) as $finding) {
            $findings[] = $finding;
        }

        return $findings;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private function lintDesign(array $payload): array
    {
        $layouts = \is_array($payload['page_type_layouts'] ?? null) ? $payload['page_type_layouts'] : [];
        if ($layouts === []) {
            return [
                $this->finding('error', 'design', 'design.missing_page_layouts', 'Build render data has no page layout output.', 'payload.page_type_layouts'),
            ];
        }

        $findings = [];
        foreach ($layouts as $pageType => $layout) {
            if (!\is_array($layout)) {
                continue;
            }
            $blocks = \is_array($layout['content'] ?? null) ? $layout['content'] : [];
            if ($blocks === []) {
                $findings[] = $this->finding(
                    'error',
                    'design',
                    'design.empty_page_layout',
                    'Page layout has no rendered sections.',
                    'payload.page_type_layouts.' . (string)$pageType . '.content'
                );
                continue;
            }
            foreach ($blocks as $index => $block) {
                if (!\is_array($block)) {
                    continue;
                }
                $path = 'payload.page_type_layouts.' . (string)$pageType . '.content.' . (string)$index;
                if ($this->firstNonEmptyString([$block['code'] ?? null, $block['component'] ?? null, $block['template'] ?? null]) === '') {
                    $findings[] = $this->finding(
                        'error',
                        'design',
                        'design.missing_section_identity',
                        'Section is missing a code/component identity.',
                        $path
                    );
                }
                if (!$this->hasDesignSignal($block)) {
                    $findings[] = $this->finding(
                        'warning',
                        'design',
                        'design.missing_design_tokens',
                        'Section is missing design tokens, style plan, or design tags for visual QA.',
                        $path
                    );
                }
                $htmlStructureReason = $this->detectBlockMalformedHtmlStructureReason($block);
                if ($htmlStructureReason !== null) {
                    $findings[] = $this->finding(
                        'error',
                        'design',
                        'design.malformed_html_structure',
                        'Section HTML has malformed tags or unbalanced structure: ' . $htmlStructureReason,
                        $path
                    );
                }
            }
        }

        return $findings;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private function lintCopy(array $payload): array
    {
        $findings = [];
        foreach ($this->iterBlocks($payload) as $entry) {
            $text = $this->extractVisibleText($entry['block']);
            if ($text === '') {
                $findings[] = $this->finding(
                    'warning',
                    'copy',
                    'copy.empty_visible_copy',
                    'Section has no visible copy for visitors.',
                    $entry['path']
                );
                continue;
            }
            if ($this->isGenericCopy($text)) {
                $findings[] = $this->finding(
                    'warning',
                    'copy',
                    'copy.generic_or_placeholder',
                    'Section copy looks generic, placeholder-like, or internal-instruction-like.',
                    $entry['path']
                );
            }
        }

        return $findings;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array<string, mixed>>
     */
    private function lintSeo(array $payload): array
    {
        $layouts = \is_array($payload['page_type_layouts'] ?? null) ? $payload['page_type_layouts'] : [];
        $pages = \is_array($payload['materialized_pages_by_type'] ?? null) ? $payload['materialized_pages_by_type'] : [];
        $findings = [];

        foreach ($layouts as $pageType => $layout) {
            $page = \is_array($pages[$pageType] ?? null) ? $pages[$pageType] : [];
            $layout = \is_array($layout) ? $layout : [];
            $seo = \is_array($page['seo'] ?? null) ? $page['seo'] : (\is_array($layout['seo'] ?? null) ? $layout['seo'] : []);
            $title = $this->firstNonEmptyString([
                $seo['title'] ?? null,
                $page['seo_title'] ?? null,
                $page['meta_title'] ?? null,
                $layout['seo_title'] ?? null,
                $layout['title'] ?? null,
            ]);
            $description = $this->firstNonEmptyString([
                $seo['description'] ?? null,
                $page['seo_description'] ?? null,
                $page['meta_description'] ?? null,
                $layout['seo_description'] ?? null,
                $layout['description'] ?? null,
            ]);
            $h1 = $this->firstNonEmptyString([
                $page['h1'] ?? null,
                $layout['h1'] ?? null,
                $layout['headline'] ?? null,
                $layout['title'] ?? null,
            ]);

            $basePath = 'payload.materialized_pages_by_type.' . (string)$pageType;
            if ($title === '') {
                $findings[] = $this->finding('warning', 'seo', 'seo.missing_title', 'Page is missing SEO title metadata.', $basePath . '.seo_title');
            } elseif (\mb_strlen($title) < 8) {
                $findings[] = $this->finding('warning', 'seo', 'seo.short_title', 'SEO title is too short to describe the page intent.', $basePath . '.seo_title');
            }
            if ($description === '') {
                $findings[] = $this->finding('warning', 'seo', 'seo.missing_description', 'Page is missing SEO description metadata.', $basePath . '.seo_description');
            } elseif (\mb_strlen($description) < 24) {
                $findings[] = $this->finding('warning', 'seo', 'seo.short_description', 'SEO description is too short to summarize the page.', $basePath . '.seo_description');
            }
            if ($h1 === '') {
                $findings[] = $this->finding('warning', 'seo', 'seo.missing_h1', 'Page is missing a visible H1/headline signal.', 'payload.page_type_layouts.' . (string)$pageType . '.h1');
            }
        }

        return $findings;
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<array{path:string,block:array<string,mixed>}>
     */
    private function iterBlocks(array $payload): array
    {
        $layouts = \is_array($payload['page_type_layouts'] ?? null) ? $payload['page_type_layouts'] : [];
        $entries = [];
        foreach ($layouts as $pageType => $layout) {
            if (!\is_array($layout)) {
                continue;
            }
            foreach (\is_array($layout['content'] ?? null) ? $layout['content'] : [] as $index => $block) {
                if (!\is_array($block)) {
                    continue;
                }
                $entries[] = [
                    'path' => 'payload.page_type_layouts.' . (string)$pageType . '.content.' . (string)$index,
                    'block' => $block,
                ];
            }
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $block
     */
    private function hasDesignSignal(array $block): bool
    {
        foreach (['design_tokens', 'design_tags', 'style_plan', 'style_tokens', 'section_style', 'styles', 'style'] as $key) {
            if (\is_array($block[$key] ?? null) && $block[$key] !== []) {
                return true;
            }
            if (\is_string($block[$key] ?? null) && \trim((string)$block[$key]) !== '') {
                return true;
            }
        }

        return false;
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
     * @param array<string, mixed> $block
     */
    private function extractVisibleText(array $block): string
    {
        $parts = [];
        foreach (['title', 'heading', 'headline', 'subtitle', 'description', 'copy', 'text', 'html'] as $key) {
            $value = $block[$key] ?? null;
            if (\is_scalar($value)) {
                $text = \trim(\strip_tags((string)$value));
                if ($text !== '') {
                    $parts[] = $text;
                }
            }
        }
        foreach (['fields', 'content', 'realtime_content'] as $key) {
            if (\is_array($block[$key] ?? null)) {
                $this->collectTextLeaves($block[$key], $parts, 6);
            }
        }

        return \trim(\implode(' ', \array_unique($parts)));
    }

    /**
     * @param array<int|string, mixed> $source
     * @param list<string> $parts
     */
    private function collectTextLeaves(array $source, array &$parts, int $depth): void
    {
        if ($depth <= 0) {
            return;
        }
        foreach ($source as $value) {
            if (\is_scalar($value)) {
                $text = \trim(\strip_tags((string)$value));
                if ($text !== '') {
                    $parts[] = $text;
                }
                continue;
            }
            if (\is_array($value)) {
                $this->collectTextLeaves($value, $parts, $depth - 1);
            }
        }
    }

    private function isGenericCopy(string $text): bool
    {
        $normalized = \mb_strtolower($text);
        foreach ([
            'lorem ipsum',
            'click here',
            'learn more',
            'welcome to our website',
            'coming soon',
            'todo',
            'tbd',
            'write the',
            'fill in',
            'placeholder',
            '待补充',
            '点击这里',
            '了解更多',
            '欢迎来到',
            '这里会展示',
            '围绕',
            '突出卖点',
        ] as $marker) {
            if (\str_contains($normalized, \mb_strtolower($marker))) {
                return true;
            }
        }

        return false;
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

<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\QA;

final class RenderDataQualityLinter
{
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
        $layouts = \is_array($payload['page_type_layouts'] ?? null) ? $payload['page_type_layouts'] : [];
        if ($layouts === []) {
            return [
                $this->finding('error', 'structure', 'structure.missing_page_layouts', 'Build render data has no page layout output.', 'payload.page_type_layouts'),
            ];
        }

        $findings = [];
        foreach ($layouts as $pageType => $layout) {
            if (!\is_array($layout)) {
                $findings[] = $this->finding(
                    'error',
                    'structure',
                    'structure.invalid_page_layout',
                    'Page layout must be an object.',
                    'payload.page_type_layouts.' . (string)$pageType
                );
                continue;
            }
            $blocks = \is_array($layout['content'] ?? null) ? $layout['content'] : [];
            if ($blocks === []) {
                $findings[] = $this->finding(
                    'error',
                    'structure',
                    'structure.empty_page_layout',
                    'Page layout has no rendered sections.',
                    'payload.page_type_layouts.' . (string)$pageType . '.content'
                );
                continue;
            }
            foreach ($blocks as $index => $block) {
                if (!\is_array($block)) {
                    $findings[] = $this->finding(
                        'error',
                        'structure',
                        'structure.invalid_section',
                        'Section must be an object.',
                        'payload.page_type_layouts.' . (string)$pageType . '.content.' . (string)$index
                    );
                    continue;
                }
                $path = 'payload.page_type_layouts.' . (string)$pageType . '.content.' . (string)$index;
                if ($this->firstNonEmptyString([$block['code'] ?? null, $block['component'] ?? null, $block['template'] ?? null]) === '') {
                    $findings[] = $this->finding(
                        'error',
                        'structure',
                        'structure.missing_section_identity',
                        'Section is missing a code/component identity.',
                        $path
                    );
                }
                $htmlStructureReason = $this->detectBlockMalformedHtmlStructureReason($block);
                if ($htmlStructureReason !== null) {
                    $findings[] = $this->finding(
                        'error',
                        'structure',
                        'structure.malformed_html',
                        'Section HTML has malformed tags or unbalanced structure: ' . $htmlStructureReason,
                        $path
                    );
                }
            }
        }

        return $findings;
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

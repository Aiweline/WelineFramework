<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Contract;

final class PlanJsonContentManifestLinter
{
    private const GENERIC_CTA_TEXT = [
        'learn more',
        'read more',
        'more',
        'details',
        'contact us',
        'consult now',
        'download now',
        "\u{4E86}\u{89E3}\u{8BE6}\u{60C5}",
        "\u{8054}\u{7CFB}\u{6211}\u{4EEC}",
        "\u{7ACB}\u{5373}\u{54A8}\u{8BE2}",
        "\u{7ACB}\u{5373}\u{4E0B}\u{8F7D}",
        '了解更多',
        '查看更多',
        '更多',
    ];

    /**
     * @param array<string, mixed> $contract
     * @return array{valid:bool,errors:list<string>}
     */
    public function validate(array $contract): array
    {
        $errors = [];
        $contentManifest = \is_array($contract['content_manifest'] ?? null) ? $contract['content_manifest'] : [];
        $items = $this->normalizeItems($contentManifest['items'] ?? []);
        if ($items === []) {
            $errors[] = 'content_manifest.items must not be empty';
        }

        $i18n = \is_array($contract['i18n'] ?? null) ? $contract['i18n'] : [];
        $primaryLocale = \trim((string)($contentManifest['primary_locale'] ?? ''));
        $i18nPrimaryLocale = \trim((string)($i18n['primary_locale'] ?? ''));
        if ($primaryLocale !== '' && $i18nPrimaryLocale !== '' && $primaryLocale !== $i18nPrimaryLocale) {
            $errors[] = 'content_manifest.primary_locale must match i18n.primary_locale';
        }
        $allowedLatinWords = $this->buildAllowedLatinWords($contract);
        $effectiveLocale = $primaryLocale !== '' ? $primaryLocale : $i18nPrimaryLocale;
        foreach ($items as $key => $value) {
            if ($this->looksLikePlaceholder($value)) {
                $errors[] = 'content_manifest item has placeholder copy: ' . $key;
            }
            if (!$this->isPolicyMetadataContentKey($key) && $this->looksLikePlanningOrImplementationCopy($value)) {
                $errors[] = 'content_manifest item has planning or implementation copy: ' . $key;
            }
            if (!$this->isPolicyMetadataContentKey($key) && $this->looksLikeLocaleLeak($value, $effectiveLocale, $key, $allowedLatinWords)) {
                $errors[] = 'content_manifest item has locale leakage: ' . $key;
            }
        }
        if ($this->ctaCopyIsAllGeneric($items)) {
            $errors[] = 'content_manifest CTA copy is too generic across multiple actions';
        }
        foreach ($this->normalizeRecordSet($contract['pages'] ?? [], ['page_id', 'id']) as $pageId => $page) {
            foreach (['title_key', 'description_key'] as $field) {
                $key = \trim((string)($page[$field] ?? ''));
                if ($key !== '' && !isset($items[$key])) {
                    $errors[] = 'Page ' . $pageId . ' references missing content key: ' . $field . '=' . $key;
                }
            }
        }

        $blocksById = $this->extractPlanJsonBlocks($contract);
        foreach ($blocksById as $blockId => $block) {
            foreach ($this->stringList($block['content_keys'] ?? []) as $key) {
                if (!isset($items[$key])) {
                    $errors[] = 'Block ' . $blockId . ' references missing content key: ' . $key;
                }
            }
        }

        return [
            'valid' => $errors === [],
            'errors' => \array_values(\array_unique($errors)),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function normalizeItems(mixed $items): array
    {
        if (!\is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $key => $value) {
            if (\is_array($value) && \is_int($key)) {
                $key = \trim((string)($value['key'] ?? $value['content_key'] ?? $value['id'] ?? ''));
            }
            $key = \trim((string)$key);
            if ($key === '') {
                continue;
            }
            $normalized[$key] = $this->extractTextValue($value);
        }

        return $normalized;
    }

    private function extractTextValue(mixed $value): string
    {
        if (\is_scalar($value) || (\is_object($value) && \method_exists($value, '__toString'))) {
            return \trim((string)$value);
        }
        if (!\is_array($value)) {
            return '';
        }
        foreach (['text', 'value', 'copy', 'primary_text', 'content'] as $field) {
            if (isset($value[$field]) && (\is_scalar($value[$field]) || (\is_object($value[$field]) && \method_exists($value[$field], '__toString')))) {
                return \trim((string)$value[$field]);
            }
        }
        if (\is_array($value['locales'] ?? null)) {
            foreach ($value['locales'] as $localeText) {
                if (\is_scalar($localeText) || (\is_object($localeText) && \method_exists($localeText, '__toString'))) {
                    return \trim((string)$localeText);
                }
            }
        }

        return \implode(' ', $this->collectVisibleTextLeaves($value));
    }

    /**
     * @param array<string, mixed> $contract
     * @return array<string, array<string, mixed>>
     */
    private function extractPlanJsonBlocks(array $contract): array
    {
        $pages = \is_array($contract['pages'] ?? null) ? $contract['pages'] : [];
        $blocks = [];
        foreach ($pages as $pageType => $page) {
            if (!\is_array($page)) {
                continue;
            }
            foreach ($this->extractPageBlocks($page) as $blockKey => $block) {
                $blockId = \trim((string)($block['block_id'] ?? $block['id'] ?? $blockKey));
                if ($blockId === '') {
                    continue;
                }
                $blocks[$blockId] = $block + [
                    'block_key' => (string)$blockKey,
                    'page_type' => (string)$pageType,
                ];
            }
        }

        return $blocks;
    }

    /**
     * @param array<string, mixed> $page
     * @return array<string, array<string, mixed>>
     */
    private function extractPageBlocks(array $page): array
    {
        $reserved = [
            'page_id' => true,
            'id' => true,
            'page_type' => true,
            'type' => true,
            'title' => true,
            'description' => true,
            'page_goal' => true,
            'page_design_plan' => true,
            'theme_alignment_summary' => true,
            'status' => true,
            'seo' => true,
            'route' => true,
            'meta' => true,
            'layout' => true,
            'blocks' => true,
            'block_previews' => true,
            'sections' => true,
            'components' => true,
        ];
        $blocks = [];
        foreach ($page as $key => $value) {
            if (!\is_string($key) || isset($reserved[$key]) || !\is_array($value)) {
                continue;
            }
            $blocks[$key] = $value;
        }

        return $blocks;
    }

    private function isPolicyMetadataContentKey(string $key): bool
    {
        return \in_array($key, [
            'site.allowed_brand_terms',
            'site.forbidden_template_brand_terms',
        ], true);
    }

    /**
     * @return list<string>
     */
    private function collectVisibleTextLeaves(mixed $value): array
    {
        if (\is_scalar($value) || (\is_object($value) && \method_exists($value, '__toString'))) {
            $text = \trim((string)$value);
            return $text !== '' ? [$text] : [];
        }
        if (!\is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $key => $item) {
            $keyText = \strtolower(\trim((string)$key));
            if ($this->isStructuralTextField($keyText) && (\is_scalar($item) || (\is_object($item) && \method_exists($item, '__toString')))) {
                continue;
            }
            foreach ($this->collectVisibleTextLeaves($item) as $text) {
                $result[] = $text;
            }
        }

        return \array_values(\array_unique($result));
    }

    private function isStructuralTextField(string $key): bool
    {
        if ($key === '') {
            return false;
        }

        return \in_array($key, [
            'key',
            'content_key',
            'id',
            'field',
            'name',
            'type',
            'role',
            'page_id',
            'page_type',
            'block_id',
            'block_key',
            'section_key',
            'task_id',
            'task_key',
        ], true);
    }

    /**
     * @param list<string> $idFields
     * @return array<string, array<string, mixed>>
     */
    private function normalizeRecordSet(mixed $items, array $idFields): array
    {
        if (!\is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $key => $item) {
            if (!\is_array($item)) {
                continue;
            }
            $id = '';
            foreach ($idFields as $field) {
                $id = \trim((string)($item[$field] ?? ''));
                if ($id !== '') {
                    break;
                }
            }
            if ($id === '' && \is_string($key)) {
                $id = $key;
            }
            if ($id !== '') {
                $normalized[$id] = $item;
            }
        }

        return $normalized;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $values): array
    {
        if (!\is_array($values)) {
            return [];
        }

        $result = [];
        foreach ($values as $value) {
            $text = \trim((string)$value);
            if ($text !== '') {
                $result[] = $text;
            }
        }

        return \array_values(\array_unique($result));
    }

    private function looksLikePlaceholder(string $value): bool
    {
        $normalized = \strtolower(\trim($value));
        if ($normalized === '') {
            return true;
        }

        foreach (['lorem ipsum', 'todo', 'placeholder', 'dummy copy', 'sample text', 'your text here', '待填写', '示例文案'] as $needle) {
            if ($needle === 'todo') {
                if (\preg_match('/(?:^|[^a-z0-9_])todo(?:$|[^a-z0-9_])/i', $normalized) === 1) {
                    return true;
                }
                continue;
            }
            if (\str_contains($normalized, \strtolower($needle))) {
                return true;
            }
        }

        return false;
    }

    public static function isPlanningOrImplementationCopy(string $value): bool
    {
        $normalized = \strtolower(\trim($value));
        if ($normalized === '') {
            return false;
        }

        $patterns = [
            '/\b(?:full[- ]?screen|hero\s+(?:area|section)|card\s+grid|grid\s+cards?|hover|pulse|shadow|background\s+gradient|left\s+and\s+right|two[- ]?column|four[- ]?column|layout|section\s+composition)\b/i',
            '/\b(?:showcase|highlight|explain|introduce|reassure|educate|encourage|guide)\b.{0,90}\b(?:visitor|player|user|download|trust|cta|card|section|block)\b/i',
            '/(?:全屏|英雄区|卡片网格|四列|两侧|背景|渐变|粒子|按钮|脉冲|hover|阴影|布局|模块).{0,80}(?:展示|呈现|引导|说明|吸引|建立|减少|转化|下载)/u',
            '/(?:首页|关于页|联系页|内容页|当前页|这个区块|这个模块).{0,40}(?:讲清|呈现|解释|说明|帮助|承接|引导|建立|减少|展示)/u',
            '/(?:为什么|为何).{0,30}(?:添加|需要|选择|放置|存在)(?:这个|该)?(?:块|区块|模块|页面)/u',
        ];
        foreach ($patterns as $pattern) {
            if (\preg_match($pattern, $value) === 1) {
                return true;
            }
        }

        return false;
    }

    private function looksLikePlanningOrImplementationCopy(string $value): bool
    {
        return self::isPlanningOrImplementationCopy($value);
    }

    /**
     * @param array<string, true> $allowedLatinWords
     */
    private function looksLikeLocaleLeak(string $value, string $locale, string $key, array $allowedLatinWords = []): bool
    {
        $locale = \strtolower(\trim($locale));
        if ($locale === '' || $value === '' || \in_array($key, ['site.name'], true)) {
            return false;
        }

        if ($this->isCjkLocale($locale)) {
            return $this->hasDominantLatinCopy($value, $allowedLatinWords);
        }
        if (!$this->isCjkLocale($locale)) {
            return $this->hasMeaningfulCjkCopy($value);
        }

        return false;
    }

    private function isCjkLocale(string $locale): bool
    {
        return $locale === 'zh'
            || \str_starts_with($locale, 'zh_')
            || \str_starts_with($locale, 'zh-')
            || $locale === 'ja'
            || \str_starts_with($locale, 'ja_')
            || \str_starts_with($locale, 'ja-')
            || $locale === 'ko'
            || \str_starts_with($locale, 'ko_')
            || \str_starts_with($locale, 'ko-');
    }

    /**
     * @param array<string, true> $allowedLatinWords
     */
    private function hasDominantLatinCopy(string $value, array $allowedLatinWords = []): bool
    {
        $allowed = \array_replace(
            \array_fill_keys(['apk', 'app', 'seo', 'ios', 'android', 'upi', 'ssl', 'vip', 'faq', 'url', 'www'], true),
            $allowedLatinWords
        );
        \preg_match_all('/\b[A-Za-z][A-Za-z0-9\'-]{2,}\b/u', $value, $matches);
        $words = [];
        $nonProperWords = [];
        $properNounOnly = true;
        foreach ($matches[0] ?? [] as $word) {
            $rawWord = \trim((string)$word, " \t\n\r\0\x0B'\"-");
            $normalized = \strtolower(\trim((string)$word, " \t\n\r\0\x0B'\"-"));
            if ($normalized !== '' && !isset($allowed[$normalized])) {
                $words[] = $normalized;
                if (\preg_match('/^[A-Z][A-Za-z0-9\'-]*$/', $rawWord) !== 1) {
                    $properNounOnly = false;
                    $nonProperWords[] = $normalized;
                }
            }
        }
        if ($words === []) {
            return false;
        }

        if (!$this->hasMeaningfulCjkCopy($value)) {
            $letterCount = 0;
            foreach ($words as $word) {
                $letterCount += \strlen($word);
            }

            return \count($words) >= 3 && $letterCount >= 16;
        }

        if ($properNounOnly) {
            return false;
        }
        if (\count($nonProperWords) <= 2) {
            return false;
        }

        $letterCount = 0;
        foreach ($nonProperWords as $word) {
            $letterCount += \strlen($word);
        }
        $cjkCount = $this->countCjkCharacters($value);

        return \count($nonProperWords) >= 8
            && $letterCount >= \max(40, (int)\ceil($cjkCount * 0.75));
    }

    /**
     * PlanJson visible copy can legitimately contain source-provided brand,
     * product, and game names in Latin characters inside CJK copy.
     *
     * @param array<string, mixed> $contract
     * @return array<string, true>
     */
    private function buildAllowedLatinWords(array $contract): array
    {
        $source = [
            'source_of_truth' => $contract['source_of_truth']['user_requirements'] ?? $contract['source_of_truth'] ?? [],
            'site_brief' => $contract['site_brief'] ?? [],
            'design_manifest' => $contract['design_manifest'] ?? [],
        ];
        $text = (string)\json_encode($source, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR);
        \preg_match_all('/\b[A-Za-z][A-Za-z0-9\'-]{2,}\b/u', $text, $matches);

        $allowed = [];
        foreach ($matches[0] ?? [] as $word) {
            $rawWord = \trim((string)$word, " \t\n\r\0\x0B'\"-");
            $normalized = \strtolower($rawWord);
            if ($normalized === '') {
                continue;
            }
            if (
                \preg_match('/^[A-Z][A-Za-z0-9\'-]*$/', $rawWord) === 1
                || \preg_match('/^[A-Z0-9]{2,}$/', $rawWord) === 1
            ) {
                $allowed[$normalized] = true;
            }
        }

        return $allowed;
    }

    private function countCjkCharacters(string $value): int
    {
        if (\preg_match_all('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]/u', $value, $matches) <= 0) {
            return 0;
        }

        return \count($matches[0] ?? []);
    }

    private function hasMeaningfulCjkCopy(string $value): bool
    {
        if (\preg_match_all('/[\p{Han}\p{Hiragana}\p{Katakana}\p{Hangul}]+/u', $value, $matches) <= 0) {
            return false;
        }

        $total = 0;
        foreach ($matches[0] ?? [] as $segment) {
            $length = \function_exists('mb_strlen') ? \mb_strlen((string)$segment) : \strlen((string)$segment);
            $total += $length;
            if ($length >= 8) {
                return true;
            }
        }

        return $total >= 12;
    }

    /**
     * @param array<string, string> $items
     */
    private function ctaCopyIsAllGeneric(array $items): bool
    {
        $ctaValues = [];
        foreach ($items as $key => $value) {
            $keyLower = \strtolower($key);
            if (!\str_contains($keyLower, 'cta') && !\str_contains($keyLower, 'button') && !\str_contains($keyLower, 'action')) {
                continue;
            }
            $text = \strtolower(\trim($value));
            if ($text !== '') {
                $ctaValues[] = $text;
            }
        }
        if (\count($ctaValues) < 2) {
            return false;
        }

        foreach ($ctaValues as $text) {
            if (!\in_array($text, self::GENERIC_CTA_TEXT, true)) {
                return false;
            }
        }

        return true;
    }
}

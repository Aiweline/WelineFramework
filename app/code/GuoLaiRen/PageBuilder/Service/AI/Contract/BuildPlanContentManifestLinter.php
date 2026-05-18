<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\Contract;

final class BuildPlanContentManifestLinter
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

        foreach ($this->normalizeRecordSet($contract['pages'] ?? [], ['page_id', 'id']) as $pageId => $page) {
            foreach (['title_key', 'description_key'] as $field) {
                $key = \trim((string)($page[$field] ?? ''));
                if ($key !== '' && !isset($items[$key])) {
                    $errors[] = 'Page ' . $pageId . ' references missing content key: ' . $field . '=' . $key;
                }
            }
        }

        foreach ($this->normalizeRecordSet($contract['blocks'] ?? [], ['block_id', 'id']) as $blockId => $block) {
            foreach ($this->stringList($block['content_keys'] ?? []) as $key) {
                if (!isset($items[$key])) {
                    $errors[] = 'Block ' . $blockId . ' references missing content key: ' . $key;
                }
            }
        }

        foreach ($items as $key => $value) {
            if ($this->looksLikePlaceholder($value)) {
                $errors[] = 'content_manifest.items contains placeholder copy: ' . $key;
            }
            if ($this->looksLikePlanningOrImplementationCopy($value)) {
                $errors[] = 'content_manifest.items contains planning or implementation copy: ' . $key;
            }
            if ($this->looksLikeLocaleLeak($value, $primaryLocale, $key)) {
                $errors[] = 'content_manifest.items contains non-locale visible copy: ' . $key;
            }
        }

        if ($this->ctaCopyIsAllGeneric($items)) {
            $errors[] = 'CTA copy cannot all be generic learn-more text';
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

        return \trim((string)\json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR));
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

    private function looksLikeLocaleLeak(string $value, string $locale, string $key): bool
    {
        $locale = \strtolower(\trim($locale));
        if ($locale === '' || $value === '' || \in_array($key, ['site.name'], true)) {
            return false;
        }

        if ($this->isCjkLocale($locale)) {
            return $this->hasDominantLatinCopy($value);
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

    private function hasDominantLatinCopy(string $value): bool
    {
        $allowed = \array_fill_keys(['apk', 'app', 'seo', 'ios', 'android', 'upi', 'ssl', 'vip', 'faq', 'url', 'www'], true);
        \preg_match_all('/\b[A-Za-z][A-Za-z0-9\'-]{2,}\b/u', $value, $matches);
        $words = [];
        foreach ($matches[0] ?? [] as $word) {
            $normalized = \strtolower(\trim((string)$word, " \t\n\r\0\x0B'\"-"));
            if ($normalized !== '' && !isset($allowed[$normalized])) {
                $words[] = $normalized;
            }
        }
        if ($words === []) {
            return false;
        }

        $letterCount = 0;
        foreach ($words as $word) {
            $letterCount += \strlen($word);
        }

        return \count($words) >= 3 && $letterCount >= 16;
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

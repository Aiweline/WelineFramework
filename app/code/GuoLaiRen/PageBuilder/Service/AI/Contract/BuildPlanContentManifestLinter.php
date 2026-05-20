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
        $sourceText = $this->normalizeSourceTextForExactContactChecks($contract);

        foreach ($this->normalizeRecordSet($contract['pages'] ?? [], ['page_id', 'id']) as $pageId => $page) {
            foreach (['title_key', 'description_key'] as $field) {
                $key = \trim((string)($page[$field] ?? ''));
                if ($key !== '' && !isset($items[$key])) {
                    $errors[] = 'Page ' . $pageId . ' references missing content key: ' . $field . '=' . $key;
                }
            }
        }

        $blocksById = $this->normalizeRecordSet($contract['blocks'] ?? [], ['block_id', 'id']);
        foreach ($blocksById as $blockId => $block) {
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
            if ($this->containsInventedExactContact($value, $sourceText)) {
                $errors[] = 'content_manifest.items contains exact contact value not present in source truth: ' . $key;
            }
        }

        foreach ($blocksById as $blockId => $block) {
            $pageType = \trim((string)($block['page_type'] ?? ''));
            if (!$this->isPolicyPageType($pageType)) {
                continue;
            }
            foreach ($this->stringList($block['content_keys'] ?? []) as $key) {
                $value = $items[$key] ?? '';
                if ($value === '') {
                    continue;
                }
                if ($this->isPolicyUnsafeContentKey($key, $value)) {
                    $errors[] = 'Policy page block ' . $blockId . ' contains app/download/reward CTA copy: ' . $key;
                }
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

        return \implode(' ', $this->collectVisibleTextLeaves($value));
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

    private function isPolicyPageType(string $pageType): bool
    {
        return \in_array($pageType, [
            'privacy_policy',
            'terms_of_service',
            'refund_policy',
            'shipping_policy',
            'cookie_policy',
        ], true);
    }

    /**
     * @param array<string,mixed> $contract
     */
    private function normalizeSourceTextForExactContactChecks(array $contract): string
    {
        $source = [
            'source_of_truth' => $contract['source_of_truth'] ?? [],
            'source_truth_contract' => $contract['source_truth_contract'] ?? [],
            'user_requirements' => $contract['user_requirements'] ?? [],
        ];

        return \mb_strtolower((string)\json_encode($source, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR));
    }

    private function containsInventedExactContact(string $value, string $sourceText): bool
    {
        if (\trim($value) === '') {
            return false;
        }
        if (\preg_match_all('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/iu', $value, $emails) > 0) {
            foreach ($emails[0] ?? [] as $email) {
                if (!\str_contains($sourceText, \mb_strtolower((string)$email))) {
                    return true;
                }
            }
        }
        if (\preg_match_all('/(?<!\d)(?:\+?\d[\d\s().-]{6,}\d)(?!\d)/u', $value, $phones) > 0) {
            $sourceDigits = \preg_replace('/\D+/', '', $sourceText) ?? '';
            foreach ($phones[0] ?? [] as $phone) {
                $digits = \preg_replace('/\D+/', '', (string)$phone) ?? '';
                if (\strlen($digits) >= 8 && !\str_contains($sourceDigits, $digits)) {
                    return true;
                }
            }
        }
        if ($this->containsSupportHoursClaim($value) && !$this->containsSupportHoursClaim($sourceText)) {
            return true;
        }

        return false;
    }

    private function containsSupportHoursClaim(string $value): bool
    {
        return \preg_match('/(?:24\s*\/\s*7|24\s*(?:h|hours?)|\x{0032}\x{0034}\s*\x{5C0F}\x{65F6}|\x{4E8C}\x{5341}\x{56DB}\x{5C0F}\x{65F6}|\x{5168}\x{5929}\x{5019}|\x{5168}\x{65F6})/iu', $value) === 1;
    }

    private function isPolicyUnsafeContentKey(string $key, string $value): bool
    {
        $normalized = \mb_strtolower(\trim((string)\preg_replace('/\s+/u', ' ', $value)));
        if ($normalized === '') {
            return false;
        }

        $isCtaKey = \preg_match('/(?:^|\.)cta$|cta(?:_|-)?(?:text|label|copy)?$/iu', $key) === 1;
        if ($isCtaKey) {
            return \preg_match(
                '/\b(?:download|apk|app|install|play|bonus|reward|casino|rummy|ludo|teen\s*patti|claim|coins?)\b|\x{4E0B}\x{8F7D}|\x{5B89}\x{88C5}|\x{9886}\x{53D6}|\x{5956}\x{52B1}|\x{5956}\x{91D1}|\x{7B79}\x{7801}|\x{6E38}\x{620F}|\x{5F00}\x{59CB}|\x{6CE8}\x{518C}\x{5373}\x{9001}/iu',
                $normalized
            ) === 1;
        }

        $downloadPattern = '/\b(?:download|apk|install)\b|\x{4E0B}\x{8F7D}|\x{5B89}\x{88C5}/iu';
        if (\preg_match($downloadPattern, $normalized) === 1) {
            return true;
        }

        return \preg_match(
            '/\b(?:claim|bonus|coins?)\b|\x{9886}\x{53D6}.{0,12}(?:\x{5956}\x{52B1}|\x{5956}\x{91D1}|\x{7B79}\x{7801})|\x{6CE8}\x{518C}\x{5373}\x{9001}/iu',
            $normalized
        ) === 1;
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

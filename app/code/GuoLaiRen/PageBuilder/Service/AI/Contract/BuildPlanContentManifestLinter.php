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

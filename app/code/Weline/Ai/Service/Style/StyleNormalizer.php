<?php

declare(strict_types=1);

namespace Weline\Ai\Service\Style;

final class StyleNormalizer
{
    /** @var list<string> */
    public const JSON_FIELDS = [
        'industry_tags',
        'match_keywords',
        'visual_keywords',
        'color_system',
        'layout_patterns',
        'image_strategy',
        'forbidden_patterns',
        'block_rules',
        'qa_rules',
        'example_refs',
    ];

    public function normalizeCode(string $code, bool $throwOnEmpty = true): string
    {
        $code = \strtolower(\trim($code));
        $code = (string)\preg_replace('/[^a-z0-9_-]+/', '-', $code);
        $code = \trim($code, '-_');
        if ($code === '') {
            if ($throwOnEmpty) {
                throw new \InvalidArgumentException('Style code is required.');
            }
            return '';
        }
        if (\strlen($code) > 96) {
            throw new \InvalidArgumentException('Style code cannot exceed 96 characters.');
        }

        return $code;
    }

    /**
     * @return list<string>
     */
    public function normalizeCodeList(mixed $raw): array
    {
        if (\is_string($raw)) {
            $decoded = \json_decode($raw, true);
            $raw = \is_array($decoded) ? $decoded : \preg_split('/[\s,;]+/', $raw);
        }
        if (!\is_array($raw)) {
            return [];
        }

        $codes = [];
        foreach ($raw as $item) {
            if (!\is_scalar($item)) {
                continue;
            }
            try {
                $code = $this->normalizeCode((string)$item, true);
            } catch (\InvalidArgumentException) {
                continue;
            }
            if (!\in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * @return list<string>
     */
    public function normalizeStringList(mixed $raw): array
    {
        if (\is_string($raw)) {
            $decoded = $this->decodeJsonField($raw);
            if (\is_array($decoded) && $decoded !== []) {
                $raw = $decoded;
            } else {
                $raw = \preg_split('/[\r\n,;]+/u', $raw) ?: [];
            }
        }
        if (!\is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $value) {
            if (\is_array($value)) {
                foreach ($value as $nested) {
                    $text = \trim(\is_scalar($nested) ? (string)$nested : (string)\json_encode($nested, \JSON_UNESCAPED_UNICODE));
                    if ($text !== '' && !\in_array($text, $out, true)) {
                        $out[] = $text;
                    }
                }
                continue;
            }
            $text = \trim(\is_scalar($value) ? (string)$value : '');
            if ($text !== '' && !\in_array($text, $out, true)) {
                $out[] = $text;
            }
        }

        return $out;
    }

    /**
     * @return array<string|int, mixed>
     */
    public function normalizeFlexibleStructuredField(mixed $raw): array
    {
        if (\is_string($raw)) {
            $decoded = $this->decodeJsonField($raw);
            if (\is_array($decoded)) {
                return $this->normalizeNestedStructuredArray($decoded);
            }
            return $this->normalizeStringList($raw);
        }
        if (!\is_array($raw)) {
            return [];
        }

        return $this->normalizeNestedStructuredArray($raw);
    }

    /**
     * @param array<string|int, mixed> $raw
     * @return array<string|int, mixed>
     */
    public function normalizeNestedStructuredArray(array $raw): array
    {
        $out = [];
        foreach ($raw as $key => $value) {
            if (\is_array($value)) {
                $nested = $this->normalizeNestedStructuredArray($value);
                if ($nested !== []) {
                    $out[$key] = $nested;
                }
                continue;
            }
            if (!\is_scalar($value)) {
                continue;
            }
            $text = \trim((string)$value);
            if ($text !== '') {
                $out[$key] = $text;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function normalizeStylePayload(array $data): array
    {
        $normalized = [];
        foreach (self::JSON_FIELDS as $field) {
            $value = $data[$field] ?? [];
            $normalized[$field] = \in_array($field, ['color_system', 'block_rules', 'example_refs'], true)
                ? $this->normalizeFlexibleStructuredField($value)
                : $this->normalizeStringList($value);
        }

        return $normalized;
    }

    /**
     * @param array<string, mixed> $normalized
     */
    public function assertStructuredPayload(array $normalized): void
    {
        $structuredNonEmpty = 0;
        foreach (['match_keywords', 'visual_keywords', 'layout_patterns', 'image_strategy', 'forbidden_patterns', 'block_rules', 'qa_rules'] as $field) {
            if ($this->structuredFieldCount($normalized[$field] ?? []) > 0) {
                $structuredNonEmpty++;
            }
        }
        if ($structuredNonEmpty < 2) {
            throw new \InvalidArgumentException('Custom style must use structured fields; supplemental notes alone are not enough.');
        }
    }

    public function structuredFieldCount(mixed $value): int
    {
        if (!\is_array($value)) {
            return 0;
        }
        $count = 0;
        foreach ($value as $item) {
            if (\is_array($item)) {
                $count += $this->structuredFieldCount($item);
            } elseif (\trim((string)$item) !== '') {
                $count++;
            }
        }

        return $count;
    }

    public function encodeJsonField(mixed $value): string
    {
        return (string)\json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
    }

    public function decodeJsonField(mixed $raw): mixed
    {
        if (\is_array($raw)) {
            return $raw;
        }
        $raw = \is_string($raw) ? \trim($raw) : '';
        if ($raw === '') {
            return [];
        }
        try {
            $decoded = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return \is_array($decoded) ? $decoded : [];
    }

    public function lowerForMatch(string $value): string
    {
        $value = \trim($value);
        if ($value === '') {
            return '';
        }
        if (\function_exists('mb_strtolower')) {
            return \mb_strtolower($value, 'UTF-8');
        }

        return \strtolower($value);
    }
}

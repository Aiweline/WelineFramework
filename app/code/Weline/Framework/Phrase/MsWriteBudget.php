<?php

declare(strict_types=1);

namespace Weline\Framework\Phrase;

/**
 * Phrase → Memory Service 写侧预算（波3 / UC-W3-01）。
 *
 * - 只允许单词级记录（`found`+`translation`）或其它有界小值进 MS。
 * - 禁止整 locale / 模块袋键与「大 map 袋」值写入 Shared Memory。
 * - 帧预算与 Server SessionProtocol::maxFrameBytes 对齐（契约锁相等）；
 *   Framework 不硬依赖 Server 类加载，缺类时用同数字面常量。
 */
final class MsWriteBudget
{
    /** Must match Weline\Server\Session\Server\SessionProtocol::maxFrameBytes(). */
    public const FRAME_BUDGET_BYTES = 12_582_912;

    /** Parser prefetch / exact-word batch hard cap. */
    public const WORD_BATCH_MAX_ITEMS = 200;

    /** Reserve for cmd/ns/sid/ttl NDJSON envelope around `data`. */
    public const FRAME_ENVELOPE_RESERVE_BYTES = 8192;

    /** Maps larger than this (string=>string) are treated as forbidden bags. */
    private const FORBIDDEN_BAG_ENTRY_FLOOR = 64;

    public static function maxFrameBytes(): int
    {
        $sessionProtocol = 'Weline\\Server\\Session\\Server\\SessionProtocol';
        if (\class_exists($sessionProtocol, false) || \class_exists($sessionProtocol)) {
            try {
                $live = (int)$sessionProtocol::maxFrameBytes();
                if ($live > 0) {
                    return $live;
                }
            } catch (\Throwable) {
            }
        }

        return self::FRAME_BUDGET_BYTES;
    }

    public static function maxPayloadBytes(): int
    {
        return \max(1024, self::maxFrameBytes() - self::FRAME_ENVELOPE_RESERVE_BYTES);
    }

    public static function isForbiddenBagKey(string $key): bool
    {
        $key = \trim($key);
        if ($key === '') {
            return true;
        }
        if (\str_starts_with($key, 'global_dictionary_module_words|')) {
            return true;
        }
        if (\str_contains($key, 'generated/language/')) {
            return true;
        }
        if (\str_contains($key, '|locale_bag|') || \str_contains($key, '|whole_locale|')) {
            return true;
        }

        return false;
    }

    /**
     * Word-level MS records are `{found:bool, translation:string}`.
     * Large string=>string maps are treated as whole bags (forbidden).
     */
    public static function isForbiddenBagValue(mixed $value): bool
    {
        if (!\is_array($value) || $value === []) {
            return false;
        }
        if (\array_key_exists('found', $value) && \array_key_exists('translation', $value)) {
            return false;
        }
        $stringPairs = 0;
        foreach ($value as $k => $v) {
            if (!\is_string($k) || !\is_string($v)) {
                return false;
            }
            $stringPairs++;
            if ($stringPairs >= self::FORBIDDEN_BAG_ENTRY_FLOOR) {
                return true;
            }
        }

        return false;
    }

    public static function isForbiddenBagWrite(string $key, mixed $value): bool
    {
        return self::isForbiddenBagKey($key) || self::isForbiddenBagValue($value);
    }

    /**
     * Drop forbidden bag keys/values; keep word-level records.
     *
     * @param array<string, mixed> $records
     * @return array<string, mixed>
     */
    public static function filterAllowedWordRecords(array $records): array
    {
        $allowed = [];
        foreach ($records as $key => $value) {
            $key = (string)$key;
            if (self::isForbiddenBagWrite($key, $value)) {
                continue;
            }
            $allowed[$key] = $value;
        }

        return $allowed;
    }

    /**
     * Partition word records so each batch respects item cap and JSON frame budget.
     *
     * @param array<string, mixed> $records
     * @return list<array<string, mixed>>
     */
    public static function partitionWordRecords(array $records): array
    {
        $records = self::filterAllowedWordRecords($records);
        if ($records === []) {
            return [];
        }

        $maxItems = self::WORD_BATCH_MAX_ITEMS;
        $maxPayload = self::maxPayloadBytes();
        $batches = [];
        $current = [];
        $currentBytes = 2; // {}

        foreach ($records as $key => $value) {
            $entryJson = \json_encode([(string)$key => $value], \JSON_UNESCAPED_UNICODE);
            $entryBytes = $entryJson === false ? $maxPayload + 1 : \strlen($entryJson);
            // Strip surrounding `{` `}` when merging into object.
            $entryBytes = \max(1, $entryBytes - 2);

            $wouldItems = \count($current) + 1;
            $wouldBytes = $current === []
                ? 2 + $entryBytes
                : $currentBytes + 1 + $entryBytes; // comma

            if ($current !== [] && ($wouldItems > $maxItems || $wouldBytes > $maxPayload)) {
                $batches[] = $current;
                $current = [];
                $currentBytes = 2;
                $wouldBytes = 2 + $entryBytes;
            }

            if ($current === [] && $wouldBytes > $maxPayload) {
                // Single record still too large — skip (拒大帧); L1/DB remain authoritative.
                continue;
            }

            $current[(string)$key] = $value;
            $currentBytes = $wouldBytes;
        }

        if ($current !== []) {
            $batches[] = $current;
        }

        return $batches;
    }

    /**
     * Estimate NDJSON request size for a cache mset-shaped envelope (tests / preflight).
     *
     * @param array<string, mixed> $data
     */
    public static function estimateMsetFrameBytes(array $data, string $ns = 'cache:phrase'): int
    {
        $message = [
            'cmd' => 'mset',
            'ns' => $ns,
            'sid' => '__kv__:' . $ns,
            'data' => $data,
            'ttl' => 3600,
        ];
        $body = \json_encode($message, \JSON_UNESCAPED_UNICODE);

        return $body === false ? \PHP_INT_MAX : \strlen($body) + 1;
    }
}

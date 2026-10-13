<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Fpc\OfflineBake;

/**
 * Package schema for offline FPC bake (one website + one exact origin).
 */
final class FpcOfflineBakeSchema
{
    public const SCHEMA = 'fpc-offline-bake.v1';
    public const MANIFEST_FILE = 'manifest.json';
    public const ENTRIES_FILE = 'entries.jsonl';
    public const PAYLOADS_DIR = 'payloads';

    /**
     * @param array<string, mixed> $manifest
     * @return list<string> empty = ok
     */
    public static function validateManifest(array $manifest): array
    {
        $errors = [];
        if (($manifest['schema'] ?? '') !== self::SCHEMA) {
            $errors[] = 'schema_mismatch';
        }
        $website = \trim((string)($manifest['website_code'] ?? ''));
        if ($website === '') {
            $errors[] = 'website_code_missing';
        }
        $origin = FpcOfflineBakeOrigin::normalize((string)($manifest['primary_origin'] ?? ''));
        if ($origin === '') {
            $errors[] = 'primary_origin_invalid';
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $entry
     * @return list<string>
     */
    public static function validateEntry(array $entry, string $websiteCode, string $primaryOrigin): array
    {
        $errors = [];
        $entryWebsite = \trim((string)($entry['website_code'] ?? ''));
        if ($entryWebsite === '' || $entryWebsite !== $websiteCode) {
            $errors[] = 'website_code_mismatch';
        }
        $fullUri = \trim((string)($entry['full_uri'] ?? ''));
        $indexKey = \trim((string)($entry['index_key'] ?? $fullUri));
        if ($fullUri === '' || $indexKey === '') {
            $errors[] = 'full_uri_missing';
        } elseif (!\Weline\Framework\Http\Fpc\FpcPayloadPathIndex::isHostScopedIndexKey($indexKey)) {
            $errors[] = 'path_only_forbidden';
        } elseif (!FpcOfflineBakeOrigin::urlBelongsTo($indexKey, $primaryOrigin)) {
            $errors[] = 'origin_mismatch';
        }
        $rel = \trim((string)($entry['body_relpath'] ?? ''));
        if ($rel === '' || \str_contains($rel, '..') || \str_starts_with($rel, '/')) {
            $errors[] = 'body_relpath_invalid';
        } elseif (!\str_starts_with($rel, self::PAYLOADS_DIR . '/')) {
            $errors[] = 'body_relpath_not_under_payloads';
        }
        $encoding = \trim((string)($entry['encoding'] ?? ''));
        if ($encoding !== 'br' && $encoding !== 'identity') {
            $errors[] = 'encoding_invalid';
        }
        $lang = \trim((string)($entry['lang'] ?? ''));
        $currency = \trim((string)($entry['currency'] ?? ''));
        if ($lang === '' || $currency === '') {
            $errors[] = 'lang_currency_missing';
        }

        return $errors;
    }
}

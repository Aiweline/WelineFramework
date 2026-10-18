<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Fpc\OfflineBake;

use Weline\Framework\Cache\CacheManager;
use Weline\Framework\Cache\KeyBuilder;
use Weline\Framework\Http\Fpc\FpcHtmlDiskStore;
use Weline\Framework\Manager\ObjectManager;

/**
 * Import offline bake package into URL-tree html-disk (+ optional Shared L2 pointers).
 * Does not write legacy router-fpc-payloads / FpcPayloadPathIndex remember.
 */
final class FpcOfflineBakeImporter
{
    /**
     * @return array{ok:bool,reason?:string,imported?:int,shared_seeded?:int,failed?:list<string>}
     */
    public function import(
        string $packageDir,
        string $websiteCode,
        string $primaryOrigin,
        bool $seedShared = true,
        int $sharedTtlSeconds = 86400,
    ): array {
        $packageDir = \rtrim($packageDir, '\\/');
        $manifestPath = $packageDir . \DIRECTORY_SEPARATOR . FpcOfflineBakeSchema::MANIFEST_FILE;
        if (!\is_file($manifestPath)) {
            return ['ok' => false, 'reason' => 'manifest_missing'];
        }
        $manifestRaw = \file_get_contents($manifestPath);
        if (!\is_string($manifestRaw) || $manifestRaw === '') {
            return ['ok' => false, 'reason' => 'manifest_empty'];
        }
        $manifest = \json_decode($manifestRaw, true);
        if (!\is_array($manifest)) {
            return ['ok' => false, 'reason' => 'manifest_json_invalid'];
        }
        $manifestErrors = FpcOfflineBakeSchema::validateManifest($manifest);
        if ($manifestErrors !== []) {
            return ['ok' => false, 'reason' => 'manifest_rejected:' . \implode(',', $manifestErrors)];
        }

        $manifestWebsite = \trim((string)($manifest['website_code'] ?? ''));
        if ($manifestWebsite !== $websiteCode) {
            return ['ok' => false, 'reason' => 'website_code_mismatch'];
        }
        $manifestOrigin = FpcOfflineBakeOrigin::normalize((string)($manifest['primary_origin'] ?? ''));
        $targetOrigin = FpcOfflineBakeOrigin::normalize($primaryOrigin);
        if ($manifestOrigin === '' || $targetOrigin === '' || !FpcOfflineBakeOrigin::equals($manifestOrigin, $targetOrigin)) {
            return ['ok' => false, 'reason' => 'origin_mismatch'];
        }

        $entriesPath = $packageDir . \DIRECTORY_SEPARATOR . FpcOfflineBakeSchema::ENTRIES_FILE;
        if (!\is_file($entriesPath)) {
            return ['ok' => false, 'reason' => 'entries_missing'];
        }
        $lines = \file($entriesPath, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        if (!\is_array($lines)) {
            return ['ok' => false, 'reason' => 'entries_unreadable'];
        }

        $entries = [];
        foreach ($lines as $i => $line) {
            $entry = \json_decode((string)$line, true);
            if (!\is_array($entry)) {
                return ['ok' => false, 'reason' => 'entry_json_invalid:' . $i];
            }
            $gate = FpcOfflineBakeSchema::validateEntry($entry, $websiteCode, $targetOrigin);
            if ($gate !== []) {
                return ['ok' => false, 'reason' => 'entry_rejected:' . \implode(',', $gate) . ':' . $i];
            }
            $entries[] = $entry;
        }

        $imported = 0;
        $sharedSeeded = 0;
        $failed = [];
        foreach ($entries as $entry) {
            $rel = (string)$entry['body_relpath'];
            $src = $packageDir . \DIRECTORY_SEPARATOR . \str_replace('/', \DIRECTORY_SEPARATOR, $rel);
            if (!\is_file($src)) {
                return ['ok' => false, 'reason' => 'payload_missing:' . $rel];
            }
            $raw = \file_get_contents($src);
            if (!\is_string($raw) || $raw === '') {
                return ['ok' => false, 'reason' => 'payload_empty:' . $rel];
            }
            $expectedSha = \trim((string)($entry['content_sha256'] ?? ''));
            if ($expectedSha !== '' && !\hash_equals($expectedSha, \hash('sha256', $raw))) {
                return ['ok' => false, 'reason' => 'payload_sha_mismatch:' . $rel];
            }
            $encoding = (string)$entry['encoding'];
            $indexKey = (string)$entry['index_key'];
            $lang = (string)$entry['lang'];
            $currency = (string)$entry['currency'];
            $pageUrl = (string)($entry['full_uri'] ?? $entry['url'] ?? $indexKey);
            if ($pageUrl === '' || \preg_match('#^https?://#i', $pageUrl) !== 1) {
                return ['ok' => false, 'reason' => 'page_url_invalid:' . $indexKey];
            }

            $bodyPath = '';
            $bodyBytes = 0;
            $bodyEncoding = 'identity';
            if ($encoding === 'br') {
                $plain = '';
                if (\function_exists('brotli_uncompress')) {
                    $decoded = @\brotli_uncompress($raw);
                    $plain = \is_string($decoded) ? $decoded : '';
                }
                if (!FpcHtmlDiskStore::publish($pageUrl, $plain, $raw)) {
                    return ['ok' => false, 'reason' => 'html_disk_publish_failed:' . $indexKey];
                }
                $brPath = FpcHtmlDiskStore::logicalLiveBodyPath($pageUrl, true);
                $htmlPath = FpcHtmlDiskStore::logicalLiveBodyPath($pageUrl, false);
                if ($brPath !== null && \is_file($brPath)) {
                    $bodyPath = $brPath;
                    $bodyBytes = \strlen($raw);
                    $bodyEncoding = 'br';
                } elseif ($htmlPath !== null && \is_file($htmlPath)) {
                    $bodyPath = $htmlPath;
                    $bodyBytes = \strlen($plain);
                    $bodyEncoding = 'identity';
                } else {
                    return ['ok' => false, 'reason' => 'html_disk_missing_after_publish:' . $indexKey];
                }
            } else {
                if (!FpcHtmlDiskStore::publish($pageUrl, $raw, '')) {
                    return ['ok' => false, 'reason' => 'html_disk_publish_failed:' . $indexKey];
                }
                // publish() auto-compresses to .br when brotli is available.
                $brPath = FpcHtmlDiskStore::logicalLiveBodyPath($pageUrl, true);
                $htmlPath = FpcHtmlDiskStore::logicalLiveBodyPath($pageUrl, false);
                if ($brPath !== null && \is_file($brPath)) {
                    $bodyPath = $brPath;
                    $bodyBytes = (int)\filesize($brPath);
                    $bodyEncoding = 'br';
                } elseif ($htmlPath !== null && \is_file($htmlPath)) {
                    $bodyPath = $htmlPath;
                    $bodyBytes = \strlen($raw);
                    $bodyEncoding = 'identity';
                } else {
                    return ['ok' => false, 'reason' => 'html_disk_missing_after_publish:' . $indexKey];
                }
            }
            $imported++;

            // Staging window: Shared must not point at gen/staging paths.
            if ($seedShared && FpcHtmlDiskStore::stagingGenerationId() === null) {
                try {
                    /** @var CacheManager $cm */
                    $cm = ObjectManager::getInstance(CacheManager::class);
                    $pool = $cm->pool('router');
                    $cacheKey = KeyBuilder::build(
                        'router',
                        'offline-bake-v1:'
                        . $indexKey
                        . ':lang=' . $lang
                        . ':currency=' . $currency
                        . ':website=' . $websiteCode
                    );
                    $payload = [
                        KeyBuilder::UNIFIED_CACHE_STATUS_KEY => 200,
                        KeyBuilder::UNIFIED_CACHE_HEADERS_KEY => [
                            'Content-Type' => 'text/html; charset=utf-8',
                        ],
                        'fpc_body_file' => [
                            'path' => $bodyPath,
                            'bytes' => $bodyBytes,
                            'encoding' => $bodyEncoding,
                        ],
                        'fpc_variant' => [
                            'lang' => $lang,
                            'currency' => $currency,
                            'website' => $websiteCode,
                        ],
                        'offline_bake' => true,
                    ];
                    $pool->set($cacheKey, $payload, \max(60, $sharedTtlSeconds));
                    $sharedSeeded++;
                } catch (\Throwable $e) {
                    $failed[] = $indexKey . ':shared_seed_failed:' . $e->getMessage();
                }
            }
        }

        return [
            'ok' => true,
            'imported' => $imported,
            'shared_seeded' => $sharedSeeded,
            'failed' => $failed,
        ];
    }
}

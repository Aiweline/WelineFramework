<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Fpc\OfflineBake;

use Weline\Framework\Http\Fpc\FpcPayloadPathIndex;

/**
 * Build a one-website offline FPC bake package from host-scoped cold-disk refs.
 */
final class FpcOfflineBakeExporter
{
    /**
     * @param list<string> $extraUrls Absolute host-scoped URLs to try (in addition to path-index scan)
     * @return array{ok:bool,reason?:string,package_dir?:string,entry_count?:int,skipped?:int,failed?:list<string>}
     */
    public function export(
        string $packageDir,
        string $websiteCode,
        string $primaryOrigin,
        array $extraUrls = [],
        string $defaultLang = 'zh_Hans_CN',
        string $defaultCurrency = 'CNY',
    ): array {
        $websiteCode = \trim($websiteCode);
        $origin = FpcOfflineBakeOrigin::normalize($primaryOrigin);
        if ($websiteCode === '' || $origin === '') {
            return ['ok' => false, 'reason' => 'website_or_origin_invalid'];
        }
        $packageDir = \rtrim($packageDir, '/\\');
        if ($packageDir === '' || \is_dir($packageDir)) {
            // Refuse overwrite of existing non-empty package dirs.
            if ($packageDir !== '' && \is_dir($packageDir) && $this->dirHasEntries($packageDir)) {
                return ['ok' => false, 'reason' => 'package_dir_exists'];
            }
        }
        if (!\is_dir($packageDir) && !@\mkdir($packageDir, 0775, true) && !\is_dir($packageDir)) {
            return ['ok' => false, 'reason' => 'package_dir_create_failed'];
        }
        $payloadRoot = $packageDir . \DIRECTORY_SEPARATOR . FpcOfflineBakeSchema::PAYLOADS_DIR;
        if (!\is_dir($payloadRoot) && !@\mkdir($payloadRoot, 0775, true) && !\is_dir($payloadRoot)) {
            return ['ok' => false, 'reason' => 'payloads_dir_create_failed'];
        }

        /** @var array<string, array{index_key:string,body_file:string,cache_key:string}> $candidates */
        $candidates = [];
        foreach (FpcPayloadPathIndex::enumerateHostScopedRefs() as $ref) {
            if (!FpcOfflineBakeOrigin::urlBelongsTo($ref['index_key'], $origin)) {
                continue;
            }
            $candidates[$ref['index_key']] = $ref;
        }
        foreach ($extraUrls as $url) {
            $indexKey = FpcPayloadPathIndex::normalizeIndexKey((string)$url);
            if ($indexKey === ''
                || !FpcPayloadPathIndex::isHostScopedIndexKey($indexKey)
                || !FpcOfflineBakeOrigin::urlBelongsTo($indexKey, $origin)
            ) {
                continue;
            }
            $refs = FpcPayloadPathIndex::lookup($indexKey);
            if ($refs === []) {
                continue;
            }
            $last = $refs[\count($refs) - 1];
            $candidates[$indexKey] = [
                'index_key' => $indexKey,
                'body_file' => (string)$last['body_file'],
                'cache_key' => (string)$last['cache_key'],
            ];
        }

        $entries = [];
        $skipped = 0;
        $failed = [];
        foreach ($candidates as $ref) {
            $indexKey = $ref['index_key'];
            $bodyFile = $ref['body_file'];
            if (!\is_file($bodyFile)) {
                $skipped++;
                continue;
            }
            $bytes = \filesize($bodyFile);
            if ($bytes === false || $bytes <= 0) {
                $skipped++;
                continue;
            }
            $raw = \file_get_contents($bodyFile);
            if (!\is_string($raw) || $raw === '') {
                $failed[] = $indexKey . ':read_failed';
                continue;
            }
            $encoding = \str_ends_with(\strtolower($bodyFile), '.br') ? 'br' : 'identity';
            $sha = \hash('sha256', $raw);
            $ext = $encoding === 'br' ? '.br' : '.html';
            $rel = FpcOfflineBakeSchema::PAYLOADS_DIR
                . '/'
                . \substr($sha, 0, 2)
                . '/'
                . $sha
                . $ext;
            $dest = $packageDir . \DIRECTORY_SEPARATOR . \str_replace('/', \DIRECTORY_SEPARATOR, $rel);
            $destDir = \dirname($dest);
            if (!\is_dir($destDir) && !@\mkdir($destDir, 0775, true) && !\is_dir($destDir)) {
                $failed[] = $indexKey . ':payload_mkdir_failed';
                continue;
            }
            if (!\is_file($dest) && @\file_put_contents($dest, $raw) === false) {
                $failed[] = $indexKey . ':payload_write_failed';
                continue;
            }
            $locale = FpcOfflineBakeLocaleHint::fromFullUri($indexKey, $defaultLang, $defaultCurrency);
            $entries[] = [
                'full_uri' => $indexKey,
                'index_key' => $indexKey,
                'website_code' => $websiteCode,
                'lang' => $locale['lang'],
                'currency' => $locale['currency'],
                'variant' => [
                    'lang' => $locale['lang'],
                    'currency' => $locale['currency'],
                    'website' => $websiteCode,
                ],
                'body_relpath' => $rel,
                'encoding' => $encoding,
                'bytes' => \strlen($raw),
                'content_sha256' => $sha,
                'source_cache_key' => $ref['cache_key'],
            ];
        }

        if ($entries === []) {
            return [
                'ok' => false,
                'reason' => 'no_entries',
                'skipped' => $skipped,
                'failed' => $failed,
            ];
        }

        $manifest = [
            'schema' => FpcOfflineBakeSchema::SCHEMA,
            'website_code' => $websiteCode,
            'primary_origin' => $origin,
            'created_at' => \gmdate('c'),
            'entry_count' => \count($entries),
            'tool_version' => '1',
        ];
        $manifestErrors = FpcOfflineBakeSchema::validateManifest($manifest);
        if ($manifestErrors !== []) {
            return ['ok' => false, 'reason' => 'manifest_invalid:' . \implode(',', $manifestErrors)];
        }

        $entriesPath = $packageDir . \DIRECTORY_SEPARATOR . FpcOfflineBakeSchema::ENTRIES_FILE;
        $fh = \fopen($entriesPath, 'wb');
        if ($fh === false) {
            return ['ok' => false, 'reason' => 'entries_write_failed'];
        }
        foreach ($entries as $entry) {
            $gate = FpcOfflineBakeSchema::validateEntry($entry, $websiteCode, $origin);
            if ($gate !== []) {
                \fclose($fh);
                return ['ok' => false, 'reason' => 'entry_invalid:' . \implode(',', $gate)];
            }
            \fwrite($fh, \json_encode($entry, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n");
        }
        \fclose($fh);

        $manifestPath = $packageDir . \DIRECTORY_SEPARATOR . FpcOfflineBakeSchema::MANIFEST_FILE;
        if (@\file_put_contents(
            $manifestPath,
            \json_encode($manifest, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE) . "\n"
        ) === false) {
            return ['ok' => false, 'reason' => 'manifest_write_failed'];
        }

        return [
            'ok' => true,
            'package_dir' => $packageDir,
            'entry_count' => \count($entries),
            'skipped' => $skipped,
            'failed' => $failed,
        ];
    }

    private function dirHasEntries(string $dir): bool
    {
        $items = @\scandir($dir);
        if (!\is_array($items)) {
            return false;
        }
        foreach ($items as $item) {
            if ($item !== '.' && $item !== '..') {
                return true;
            }
        }

        return false;
    }
}

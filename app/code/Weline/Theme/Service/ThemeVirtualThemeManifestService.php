<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Theme\Model\ThemeLayout;
use Weline\Theme\Model\WelineTheme;

class ThemeVirtualThemeManifestService
{
    private const RESOURCE_TYPES = [
        'layouts',
        'partials',
        'components',
        'widgets',
        'variables',
        'colors',
        'assets',
        'config',
    ];

    private const CATALOG_TYPES = [
        'layouts',
        'partials',
        'components',
        'widgets',
        'variables',
        'colors',
    ];

    public function __construct(
        private readonly WelineTheme $welineTheme,
        private readonly ThemeBuilderSchemaService $builderSchemaService,
        private readonly ThemeResourceCatalog $resourceCatalog,
        private readonly ThemeDirectoryResolver $directoryResolver,
    ) {
    }

    public function build(
        int $themeId,
        string $area = 'frontend',
        ?string $pageType = null,
        bool $forceReload = false
    ): array {
        $area = $this->normalizeArea($area);
        $pageType = $pageType ?: ThemeLayout::PAGE_TYPE_DEFAULT;
        $theme = $this->loadTheme($themeId);
        $schema = $this->builderSchemaService->getSchema($themeId, $area, $pageType, $forceReload)->toArray();

        $files = [];
        foreach (self::RESOURCE_TYPES as $type) {
            $files[$type] = in_array($type, self::CATALOG_TYPES, true)
                ? $this->buildCatalogEntries($type, $area, $theme)
                : $this->buildDirectoryEntries($type, $area, $theme);
        }

        $slots = $this->collectSlots($schema, $files);
        $assetRefs = $this->collectAssetReferences($files);
        $summary = $this->buildSummary($files, $slots, $assetRefs);
        $quality = $this->buildExtractionQuality($area, $files);
        $coverage = $this->buildCoverage($files, $quality);

        $fingerprint = sha1((string)json_encode([
            'theme_id' => $themeId,
            'area' => $area,
            'page_type' => $pageType,
            'schema_fingerprint' => $schema['fingerprint'] ?? '',
            'files' => $this->fingerprintEntries($files),
            'slots' => $slots,
            'asset_refs' => $assetRefs,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return [
            'manifest_version' => 1,
            'generated_at' => date('c'),
            'theme' => [
                'id' => $themeId,
                'name' => (string)$theme->getName(),
                'area' => $area,
                'page_type' => $pageType,
            ],
            'fingerprint' => $fingerprint,
            'schema_fingerprint' => (string)($schema['fingerprint'] ?? ''),
            'summary' => $summary,
            'coverage' => $coverage,
            'extraction_quality' => $quality,
            'slots' => $slots,
            'asset_refs' => $assetRefs,
            'files' => $files,
            'schema' => $schema,
        ];
    }

    public function coverage(array $manifest): array
    {
        return [
            'theme' => $manifest['theme'] ?? [],
            'fingerprint' => $manifest['fingerprint'] ?? '',
            'summary' => $manifest['summary'] ?? [],
            'coverage' => $manifest['coverage'] ?? [],
            'extraction_quality' => $manifest['extraction_quality'] ?? [],
            'slots' => $manifest['slots'] ?? [],
            'asset_refs' => $manifest['asset_refs'] ?? [],
        ];
    }

    private function buildCatalogEntries(string $type, string $area, WelineTheme $theme): array
    {
        $entries = [];
        foreach ($this->resourceCatalog->getRawResources($type, $area, $theme) as $resource) {
            $filePath = (string)($resource['file_path'] ?? '');
            $entry = $this->buildFileEntry($type, $filePath, [
                'logical_path' => $this->logicalPathForResource($type, $resource),
                'relative_path' => $this->relativePathForResource($type, $resource),
                'source' => 'theme_resource_catalog',
                'layer_key' => (string)($resource['layer_key'] ?? ''),
                'layer_type' => (string)($resource['layer_type'] ?? ''),
                'theme_id' => (int)($resource['theme_id'] ?? 0),
                'theme_name' => (string)($resource['theme_name'] ?? ''),
                'slots' => is_array($resource['slots'] ?? null) ? $resource['slots'] : [],
                'meta' => is_array($resource['meta'] ?? null) ? $resource['meta'] : [],
                'params' => is_array($resource['params'] ?? null) ? $resource['params'] : [],
            ]);

            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        usort($entries, static fn(array $left, array $right): int => strcmp(
            (string)($left['logical_path'] ?? ''),
            (string)($right['logical_path'] ?? '')
        ));

        return $entries;
    }

    private function buildDirectoryEntries(string $type, string $area, WelineTheme $theme): array
    {
        $entries = [];
        $seen = [];
        foreach ($this->directoryResolver->getAreaDirectories($area, $theme) as $directory) {
            $root = rtrim((string)($directory['path'] ?? ''), '\\/') . DS . $type;
            if (!is_dir($root)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $filePath = $file->getPathname();
                $relativePath = str_replace(['/', '\\'], '/', ltrim(str_replace($root, '', $filePath), '\\/'));
                $logicalPath = $type . '/' . (
                    $type === 'assets'
                        ? $relativePath
                        : preg_replace('/\.[^.]+$/', '', $relativePath)
                );
                if (isset($seen[$logicalPath])) {
                    continue;
                }
                $seen[$logicalPath] = true;

                $entry = $this->buildFileEntry($type, $filePath, [
                    'logical_path' => $logicalPath,
                    'relative_path' => $relativePath,
                    'source' => 'theme_directory_scan',
                    'layer_key' => (string)($directory['layer_key'] ?? ''),
                    'layer_type' => (string)($directory['layer_type'] ?? ''),
                    'theme_id' => (int)($directory['theme_id'] ?? 0),
                    'theme_name' => (string)($directory['theme_name'] ?? ''),
                    'slots' => [],
                    'meta' => [],
                    'params' => [],
                ]);

                if ($entry !== null) {
                    $entries[] = $entry;
                }
            }
        }

        usort($entries, static fn(array $left, array $right): int => strcmp(
            (string)($left['logical_path'] ?? ''),
            (string)($right['logical_path'] ?? '')
        ));

        return $entries;
    }

    private function buildFileEntry(string $type, string $filePath, array $context): ?array
    {
        if ($filePath === '' || !is_file($filePath)) {
            return null;
        }

        $bytes = file_get_contents($filePath);
        if (!is_string($bytes)) {
            return null;
        }

        $content = $this->decodeContent($bytes);
        $slots = is_array($context['slots'] ?? null) ? $context['slots'] : [];
        $literalSlots = $this->extractLiteralSlots($content, $filePath);
        $slotMap = [];
        foreach (array_merge($slots, $literalSlots) as $slot) {
            if (is_array($slot) && !empty($slot['id'])) {
                $slotMap[(string)$slot['id']] = $slot;
            }
        }

        return [
            'category' => $type,
            'logical_path' => (string)($context['logical_path'] ?? ''),
            'relative_path' => (string)($context['relative_path'] ?? ''),
            'absolute_path' => str_replace(['/', '\\'], '/', $filePath),
            'extension' => strtolower((string)pathinfo($filePath, PATHINFO_EXTENSION)),
            'sha1' => sha1($bytes),
            'bytes' => strlen($bytes),
            'lines' => $content === '' ? 0 : substr_count($content, "\n") + 1,
            'layer_key' => (string)($context['layer_key'] ?? ''),
            'layer_type' => (string)($context['layer_type'] ?? ''),
            'theme_id' => (int)($context['theme_id'] ?? 0),
            'theme_name' => (string)($context['theme_name'] ?? ''),
            'source' => (string)($context['source'] ?? ''),
            'coverage_state' => 'inherited',
            'slots' => array_values($slotMap),
            'slot_ids' => array_values(array_keys($slotMap)),
            'asset_refs' => $this->extractAssetReferences($content),
            'meta' => is_array($context['meta'] ?? null) ? $context['meta'] : [],
            'params' => is_array($context['params'] ?? null) ? $context['params'] : [],
        ];
    }

    private function collectSlots(array $schema, array $files): array
    {
        $slots = [];
        foreach (($schema['slots'] ?? []) as $slotId => $slot) {
            if (is_array($slot)) {
                $slot['id'] = (string)($slot['id'] ?? $slotId);
                $slot['quality'] = 'catalog';
                $slots[(string)$slot['id']] = $slot;
            }
        }

        foreach ($files as $entries) {
            foreach ($entries as $entry) {
                foreach (($entry['slots'] ?? []) as $slot) {
                    if (!is_array($slot) || empty($slot['id'])) {
                        continue;
                    }
                    $slot['quality'] ??= (string)($entry['source'] ?? '') === 'theme_resource_catalog'
                        ? 'catalog_or_literal'
                        : 'literal';
                    $slots[(string)$slot['id']] ??= $slot;
                }
            }
        }

        ksort($slots);
        return array_values($slots);
    }

    private function collectAssetReferences(array $files): array
    {
        $refs = [];
        foreach ($files as $entries) {
            foreach ($entries as $entry) {
                foreach (($entry['asset_refs'] ?? []) as $assetRef) {
                    if (!is_array($assetRef) || empty($assetRef['value'])) {
                        continue;
                    }
                    $refs[(string)$assetRef['value']] ??= $assetRef;
                }
            }
        }

        ksort($refs);
        return array_values($refs);
    }

    private function buildSummary(array $files, array $slots, array $assetRefs): array
    {
        $categories = [];
        $totalFiles = 0;
        $totalBytes = 0;

        foreach (self::RESOURCE_TYPES as $type) {
            $entries = $files[$type] ?? [];
            $categories[$type] = count($entries);
            $totalFiles += count($entries);
            foreach ($entries as $entry) {
                $totalBytes += (int)($entry['bytes'] ?? 0);
            }
        }

        return [
            'total_files' => $totalFiles,
            'total_bytes' => $totalBytes,
            'categories' => $categories,
            'total_slots' => count($slots),
            'total_asset_refs' => count($assetRefs),
        ];
    }

    private function buildCoverage(array $files, array $quality): array
    {
        $states = [
            'inherited' => 0,
            'overridden' => 0,
            'generated' => 0,
            'intentionally_excluded' => 0,
            'unresolved' => 0,
        ];

        foreach ($files as $entries) {
            foreach ($entries as $entry) {
                $state = (string)($entry['coverage_state'] ?? 'unresolved');
                $states[$state] = ($states[$state] ?? 0) + 1;
            }
        }

        return [
            'states' => $states,
            'complete' => ($states['unresolved'] ?? 0) === 0,
            'source_of_truth' => 'runtime_manifest_service',
            'quality_warnings' => array_values(array_filter(
                array_map(static fn(array $item): string => (string)($item['warning'] ?? ''), $quality),
                static fn(string $warning): bool => $warning !== ''
            )),
        ];
    }

    private function buildExtractionQuality(string $area, array $files): array
    {
        $quality = [
            [
                'subject' => 'manifest',
                'quality' => 'runtime',
                'warning' => '',
            ],
            [
                'subject' => 'slots',
                'quality' => 'catalog_and_literal',
                'warning' => 'Rendered dynamic slots are only covered when exposed by ThemeResourceCatalog or literal markup.',
            ],
            [
                'subject' => 'assets',
                'quality' => 'static_reference_scan',
                'warning' => 'Config-driven assets and runtime-generated URLs require explicit ownership classification before rewrite.',
            ],
        ];

        if ($area === 'backend' && empty($files['layouts'] ?? []) && empty($files['partials'] ?? [])) {
            $quality[] = [
                'subject' => 'backend_area',
                'quality' => 'empty_or_unsupported',
                'warning' => 'Backend theme manifest has no layout/partial resources in the resolved theme chain.',
            ];
        }

        return $quality;
    }

    private function fingerprintEntries(array $files): array
    {
        $result = [];
        foreach ($files as $type => $entries) {
            $result[$type] = array_map(static fn(array $entry): array => [
                'logical_path' => (string)($entry['logical_path'] ?? ''),
                'sha1' => (string)($entry['sha1'] ?? ''),
                'layer_key' => (string)($entry['layer_key'] ?? ''),
            ], $entries);
        }

        return $result;
    }

    private function relativePathForResource(string $type, array $resource): string
    {
        $relativePath = (string)($resource['relative_path'] ?? '');
        if ($relativePath === '') {
            return '';
        }

        return $type . '/' . ltrim(str_replace(['/', '\\'], '/', $relativePath), '/');
    }

    private function logicalPathForResource(string $type, array $resource): string
    {
        $relativePath = $this->relativePathForResource($type, $resource);
        if ($relativePath !== '') {
            return (string)preg_replace('/\.[^.]+$/', '', $relativePath);
        }

        return (string)($resource['logical_key'] ?? '');
    }

    private function extractLiteralSlots(string $content, string $filePath): array
    {
        $slots = [];

        if (preg_match_all('/<w:slot\b([^>]*)>/i', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $attributes = $this->parseAttributes((string)$match[1]);
                $slotId = (string)($attributes['id'] ?? '');
                if ($slotId === '') {
                    continue;
                }
                $slots[$slotId] = [
                    'id' => $slotId,
                    'name' => (string)($attributes['name'] ?? $slotId),
                    'source_path' => str_replace(['/', '\\'], '/', $filePath),
                    'quality' => 'literal_w_slot',
                ];
            }
        }

        if (preg_match_all('/<[^>]+\sdata-wslot=(["\'])(.*?)\1[^>]*>/i', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $attributes = $this->parseAttributes((string)$match[0]);
                $slotId = (string)($attributes['data-wslot'] ?? '');
                if ($slotId === '') {
                    continue;
                }
                $slots[$slotId] = [
                    'id' => $slotId,
                    'name' => (string)($attributes['data-wslot-name'] ?? $slotId),
                    'source_path' => str_replace(['/', '\\'], '/', $filePath),
                    'quality' => 'literal_data_wslot',
                    'accept' => $this->splitCsv((string)($attributes['data-wslot-accept'] ?? '')),
                    'multiple' => !isset($attributes['data-wslot-multiple']) || $this->toBool((string)$attributes['data-wslot-multiple'], true),
                ];
            }
        }

        if (preg_match_all('/<[^>]+class=(["\'])(?=[^"\']*widget-slot-area)(.*?)\1[^>]*>/i', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $index => $match) {
                $attributes = $this->parseAttributes((string)$match[0]);
                $slotId = (string)($attributes['data-slot-id'] ?? $attributes['id'] ?? 'legacy-widget-slot-' . ($index + 1));
                $slots[$slotId] ??= [
                    'id' => $slotId,
                    'name' => $slotId,
                    'source_path' => str_replace(['/', '\\'], '/', $filePath),
                    'quality' => 'legacy_widget_slot_area',
                ];
            }
        }

        return array_values($slots);
    }

    private function extractAssetReferences(string $content): array
    {
        $refs = [];
        $patterns = [
            'css_url' => '/url\(\s*([\'"]?)(?<value>[^)\'"]+\.(?:png|jpe?g|webp|gif|svg|avif|ico|woff2?|ttf|eot))\1\s*\)/i',
            'module_asset' => '/(?<value>[A-Z][A-Za-z0-9]+_[A-Za-z0-9]+::[^"\'\s<>]+\.(?:png|jpe?g|webp|gif|svg|avif|ico|css|js|woff2?|ttf|eot))/i',
            'media_path' => '/(?<value>(?:pub\/)?media\/[^"\'\s<>]+\.(?:png|jpe?g|webp|gif|svg|avif))/i',
            'remote_url' => '#(?<value>https?://[^"\'\s<>]+\.(?:png|jpe?g|webp|gif|svg|avif|ico))#i',
            'static_file' => '/(?<value>[^"\'\s<>]+\.(?:png|jpe?g|webp|gif|svg|avif|ico))/i',
        ];

        foreach ($patterns as $kind => $pattern) {
            if (!preg_match_all($pattern, $content, $matches)) {
                continue;
            }
            foreach (($matches['value'] ?? []) as $value) {
                $value = trim((string)$value);
                if ($value === '' || str_starts_with($value, 'data:')) {
                    continue;
                }
                $refs[$value] ??= [
                    'value' => $value,
                    'kind' => $kind,
                    'ownership' => 'unclassified',
                    'rewrite_allowed' => false,
                    'quality' => $kind === 'static_file' ? 'literal_static_reference' : 'classified_pattern',
                ];
            }
        }

        ksort($refs);
        return array_values($refs);
    }

    private function parseAttributes(string $html): array
    {
        $attributes = [];
        if (preg_match_all('/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*(["\'])(.*?)\2/', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $attributes[strtolower((string)$match[1])] = (string)$match[3];
            }
        }

        return $attributes;
    }

    private function decodeContent(string $bytes): string
    {
        if ($bytes === '') {
            return '';
        }

        $content = @mb_convert_encoding($bytes, 'UTF-8', 'UTF-8');
        return is_string($content) ? $content : $bytes;
    }

    private function splitCsv(string $value): array
    {
        return array_values(array_filter(array_map(
            static fn(string $item): string => trim($item),
            explode(',', $value)
        ), static fn(string $item): bool => $item !== ''));
    }

    private function toBool(string $value, bool $default = false): bool
    {
        $value = strtolower(trim($value));
        if ($value === '') {
            return $default;
        }

        return in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    private function loadTheme(int $themeId): WelineTheme
    {
        $theme = clone $this->welineTheme;
        $theme->clearData()->clearQuery()->load($themeId);
        if (!$theme->getId()) {
            throw new \InvalidArgumentException((string)__('主题不存在：%{1}', [$themeId]));
        }

        return $theme;
    }

    private function normalizeArea(string $area): string
    {
        return strtolower(trim($area)) === 'backend' ? 'backend' : 'frontend';
    }
}

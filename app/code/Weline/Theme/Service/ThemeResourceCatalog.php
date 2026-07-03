<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Meta\Service\ParamDefinitionNormalizer;
use Weline\Theme\Dto\ThemeSlotDefinition;
use Weline\Theme\Helper\ComponentMetaParser;
use Weline\Theme\Model\WelineTheme;

class ThemeResourceCatalog
{
    private array $rawCache = [];
    private array $resolvedCache = [];
    private array $slotCache = [];

    public function __construct(
        private readonly ThemeDirectoryResolver $directoryResolver,
    ) {
    }

    public function getRawResources(string $type, string $area = 'frontend', ?WelineTheme $theme = null): array
    {
        $type = $this->normalizeType($type);
        $area = $this->normalizeArea($area);
        $cacheKey = $this->buildCacheKey($type, $area, $theme, 'raw');
        if (isset($this->rawCache[$cacheKey])) {
            return $this->rawCache[$cacheKey];
        }

        $resources = [];
        foreach ($this->directoryResolver->getAreaDirectories($area, $theme) as $directory) {
            $root = $directory['path'] . DS . $type;
            if (!is_dir($root)) {
                continue;
            }

            foreach ($this->iterateFiles($root, $type) as $filePath) {
                $resource = $this->buildResource($type, $area, $root, $filePath, $directory);
                if ($resource !== null) {
                    $resources[] = $resource;
                }
            }
        }

        $this->rawCache[$cacheKey] = $resources;
        return $resources;
    }

    public function getResources(string $type, string $area = 'frontend', ?WelineTheme $theme = null): array
    {
        $type = $this->normalizeType($type);
        $area = $this->normalizeArea($area);
        $cacheKey = $this->buildCacheKey($type, $area, $theme, 'resolved');
        if (isset($this->resolvedCache[$cacheKey])) {
            return $this->resolvedCache[$cacheKey];
        }

        $resources = [];
        foreach ($this->getRawResources($type, $area, $theme) as $resource) {
            $logicalKey = $resource['logical_key'] ?? '';
            if ($logicalKey === '' || isset($resources[$logicalKey])) {
                continue;
            }
            $resources[$logicalKey] = $resource;
        }

        $this->resolvedCache[$cacheKey] = $resources;
        return $resources;
    }

    public function getLayouts(string $area = 'frontend', ?WelineTheme $theme = null): array
    {
        $layouts = [];
        foreach ($this->getResources('layouts', $area, $theme) as $resource) {
            $layoutType = $resource['layout_type'];
            $layouts[$layoutType] ??= [];
            $layouts[$layoutType][] = [
                'value' => $resource['option'],
                'meta' => $resource['meta'],
                'file' => $resource['relative_path'],
                'path' => $resource['file_path'],
                'logical_key' => $resource['logical_key'],
                'layout_info' => $resource['layout_info'] ?? [],
                'layout_info_path' => $resource['layout_info_path'] ?? '',
                'layout_info_error' => $resource['layout_info_error'] ?? '',
                'layer_type' => $resource['layer_type'] ?? '',
                'layer_key' => $resource['layer_key'] ?? '',
                'module_name' => $resource['module_name'] ?? '',
            ];
        }

        foreach ($layouts as &$options) {
            usort($options, fn(array $left, array $right): int => strcmp(
                (string)($left['meta']['name'] ?? $left['value']),
                (string)($right['meta']['name'] ?? $right['value'])
            ));
        }

        return $layouts;
    }

    public function getLayoutResource(string $area, ?WelineTheme $theme, string $layoutType, string $option = 'default'): ?array
    {
        $layoutType = $this->normalizeLogicalSegment($layoutType);
        $option = $this->normalizeLogicalSegment($option);
        $logicalKey = 'layouts/' . $layoutType . '/' . ($option === '' ? 'default' : $option);

        return $this->getResources('layouts', $area, $theme)[$logicalKey] ?? null;
    }

    public function getPartials(string $area = 'frontend', ?WelineTheme $theme = null): array
    {
        $partials = [];
        foreach ($this->getResources('partials', $area, $theme) as $resource) {
            $partialType = $resource['partial_type'];
            $partials[$partialType] ??= [];
            $partials[$partialType][] = [
                'value' => $resource['option'],
                'meta' => $resource['meta'],
                'file' => $resource['relative_path'],
                'path' => $resource['file_path'],
                'logical_key' => $resource['logical_key'],
            ];
        }

        foreach ($partials as &$options) {
            usort($options, fn(array $left, array $right): int => strcmp(
                (string)($left['meta']['name'] ?? $left['value']),
                (string)($right['meta']['name'] ?? $right['value'])
            ));
        }

        return $partials;
    }

    public function getComponents(string $area = 'frontend', ?WelineTheme $theme = null): array
    {
        $components = [];
        foreach ($this->getResources('components', $area, $theme) as $resource) {
            $components[] = [
                'category' => $resource['category'],
                'value' => $resource['code'],
                'meta' => $resource['meta'],
                'params' => $resource['params'],
                'file' => $resource['relative_path'],
                'path' => $resource['file_path'],
                'logical_key' => $resource['logical_key'],
            ];
        }

        usort($components, fn(array $left, array $right): int => strcmp(
            (string)($left['meta']['name'] ?? $left['value']),
            (string)($right['meta']['name'] ?? $right['value'])
        ));

        return $components;
    }

    public function getVariables(string $area = 'frontend', ?WelineTheme $theme = null): array
    {
        return array_values(array_map(static fn(array $resource): array => [
            'value' => $resource['value'],
            'meta' => $resource['meta'],
            'file' => $resource['relative_path'],
            'path' => $resource['file_path'],
            'logical_key' => $resource['logical_key'],
        ], $this->getResources('variables', $area, $theme)));
    }

    public function getColors(string $area = 'frontend', ?WelineTheme $theme = null): array
    {
        return array_values(array_map(static fn(array $resource): array => [
            'value' => $resource['value'],
            'meta' => $resource['meta'],
            'file' => $resource['relative_path'],
            'path' => $resource['file_path'],
            'logical_key' => $resource['logical_key'],
        ], $this->getResources('colors', $area, $theme)));
    }

    public function getSlots(string $area = 'frontend', ?WelineTheme $theme = null): array
    {
        $area = $this->normalizeArea($area);
        $cacheKey = $this->buildCacheKey('slots', $area, $theme, 'resolved');
        if (isset($this->slotCache[$cacheKey])) {
            return $this->slotCache[$cacheKey];
        }

        $slots = [];
        foreach (['layouts', 'partials', 'components', 'widgets'] as $type) {
            foreach ($this->getResources($type, $area, $theme) as $resource) {
                foreach ($resource['slots'] ?? [] as $slot) {
                    if ($slot instanceof ThemeSlotDefinition) {
                        $slots[$slot->id] ??= $slot->toArray();
                    } elseif (is_array($slot) && !empty($slot['id'])) {
                        $slots[$slot['id']] ??= $slot;
                    }
                }
            }
        }

        $this->slotCache[$cacheKey] = $slots;
        return $slots;
    }

    public function clearCache(): void
    {
        $this->rawCache = [];
        $this->resolvedCache = [];
        $this->slotCache = [];
    }

    private function iterateFiles(string $root, string $type): \Generator
    {
        $extension = in_array($type, ['variables', 'colors'], true) ? 'css' : 'phtml';
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower((string)$file->getExtension()) !== $extension) {
                continue;
            }

            if ($extension === 'css' && strpos($file->getBasename('.css'), '_') !== 0) {
                continue;
            }

            yield $file->getPathname();
        }
    }

    private function buildResource(string $type, string $area, string $root, string $filePath, array $directory): ?array
    {
        $content = file_get_contents($filePath);
        if (!is_string($content)) {
            return null;
        }
        if (!in_array($type, ['variables', 'colors'], true) && trim($content) === '') {
            return null;
        }

        $relativePath = str_replace(['/', '\\'], '/', ltrim(str_replace($root, '', $filePath), '\\/'));
        $parsed = in_array($type, ['variables', 'colors'], true) ? [] : ComponentMetaParser::parse($filePath);
        $meta = $this->extractMeta($content, $parsed, pathinfo($filePath, PATHINFO_FILENAME));

        $resource = [
            'type' => $type,
            'area' => $area,
            'file_path' => $filePath,
            'relative_path' => $relativePath,
            'theme_id' => (int)($directory['theme_id'] ?? 0),
            'theme_name' => (string)($directory['theme_name'] ?? ''),
            'theme_path' => (string)($directory['theme_path'] ?? ''),
            'layer_key' => (string)($directory['layer_key'] ?? ''),
            'layer_type' => (string)($directory['layer_type'] ?? ''),
            'module_name' => (string)($directory['module_name'] ?? ''),
            'module_path' => (string)($directory['module_path'] ?? ''),
            'module_base_path' => (string)($directory['module_base_path'] ?? ''),
            'structure' => (string)($directory['structure'] ?? ''),
            'meta' => $meta,
            'params' => $this->formatParams($parsed['params'] ?? []),
            'mtime' => (int)(filemtime($filePath) ?: 0),
            'slots' => $this->extractSlots($content, $area, $filePath),
            'widget_meta' => $type === 'components' && str_contains($content, '@widget.')
                ? $this->extractWidgetMeta($content, pathinfo($filePath, PATHINFO_FILENAME))
                : [],
        ];

        return match ($type) {
            'layouts' => $this->buildLayoutResource($resource, $relativePath),
            'partials' => $this->buildPartialResource($resource, $relativePath),
            'components' => $this->buildComponentResource($resource, $relativePath),
            'widgets' => $this->buildLegacyWidgetResource($resource, $relativePath, $content),
            'variables', 'colors' => $this->buildCssResource($resource, $relativePath, $type),
            default => null,
        };
    }

    private function buildLayoutResource(array $resource, string $relativePath): array
    {
        [$layoutType, $option] = $this->resolveTypeAndOption($relativePath);
        $resource['layout_type'] = $layoutType;
        $resource['option'] = $option;
        $resource['logical_key'] = "layouts/{$layoutType}/{$option}";
        $layoutInfo = $this->loadAdjacentLayoutInfo((string)$resource['file_path']);
        $resource['layout_info'] = $layoutInfo['data'];
        $resource['layout_info_path'] = $layoutInfo['path'];
        $resource['layout_info_error'] = $layoutInfo['error'];
        return $resource;
    }

    private function buildPartialResource(array $resource, string $relativePath): array
    {
        [$partialType, $option] = $this->resolveTypeAndOption($relativePath);
        $resource['partial_type'] = $partialType;
        $resource['option'] = $option;
        $resource['logical_key'] = "partials/{$partialType}/{$option}";
        return $resource;
    }

    private function buildComponentResource(array $resource, string $relativePath): array
    {
        $path = str_replace('\\', '/', pathinfo($relativePath, PATHINFO_DIRNAME));
        $code = pathinfo($relativePath, PATHINFO_FILENAME);
        $category = ($path === '.' || $path === '') ? 'basic' : trim($path, '/');
        $resource['category'] = $category;
        $resource['code'] = $code;
        $resource['logical_key'] = "components/{$category}/{$code}";
        return $resource;
    }

    private function buildLegacyWidgetResource(array $resource, string $relativePath, string $content): array
    {
        $path = str_replace('\\', '/', pathinfo($relativePath, PATHINFO_DIRNAME));
        $parts = array_values(array_filter(explode('/', trim($path, '/'))));
        $code = count($parts) >= 2 ? $parts[count($parts) - 1] : ($parts[0] ?? pathinfo($relativePath, PATHINFO_FILENAME));
        $widgetMeta = $this->extractWidgetMeta($content, $code);
        $category = count($parts) >= 2 ? implode('/', array_slice($parts, 0, -1)) : ((string)($widgetMeta['type'] ?? 'legacy'));
        $category = trim($category, '/') ?: 'legacy';

        $resource['category'] = $category;
        $resource['code'] = $widgetMeta['code'] ?: $code;
        $resource['widget_meta'] = $widgetMeta;
        $resource['logical_key'] = "components/{$category}/{$resource['code']}";
        $resource['meta'] = array_merge($resource['meta'], [
            'widget' => $widgetMeta,
        ]);

        return $resource;
    }

    private function buildCssResource(array $resource, string $relativePath, string $type): array
    {
        $basename = pathinfo($relativePath, PATHINFO_FILENAME);
        $value = ltrim($basename, '_');
        $resource['value'] = $value;
        $resource['logical_key'] = "{$type}/{$value}";
        return $resource;
    }

    private function resolveTypeAndOption(string $relativePath): array
    {
        $segments = array_values(array_filter(explode('/', trim($relativePath, '/'))));
        $filename = pathinfo((string)end($segments), PATHINFO_FILENAME);

        if (count($segments) === 1) {
            return [$filename, 'default'];
        }

        $type = (string)array_shift($segments);
        $segments[count($segments) - 1] = $filename;
        $option = implode('/', $segments);

        return [$type, $option === '' ? 'default' : $option];
    }

    private function loadAdjacentLayoutInfo(string $filePath): array
    {
        $result = [
            'data' => [],
            'path' => '',
            'error' => '',
        ];

        if (!str_ends_with(strtolower($filePath), '.phtml')) {
            return $result;
        }

        $jsonPath = substr($filePath, 0, -6) . '.layout.json';
        if (!is_file($jsonPath)) {
            return $result;
        }

        $result['path'] = $jsonPath;
        $raw = file_get_contents($jsonPath);
        if (!is_string($raw) || trim($raw) === '') {
            return $result;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            $result['error'] = json_last_error_msg();
            return $result;
        }

        $result['data'] = $decoded;
        return $result;
    }

    private function normalizeLogicalSegment(string $value): string
    {
        $value = str_replace('\\', '/', trim($value));
        $segments = array_values(array_filter(explode('/', trim($value, '/')), static fn(string $segment): bool => $segment !== ''));

        return implode('/', $segments);
    }

    private function extractWidgetMeta(string $content, string $fallbackCode): array
    {
        $data = [
            'code' => $fallbackCode,
            'name' => '',
            'description' => '',
            'type' => '',
            'area' => '',
            'position' => [],
            'supports' => [],
            'slot' => null,
            'exclusive' => false,
            'compatible' => false,
            'is_container' => false,
            'page_layouts' => ['*'],
            'icon' => '',
            'slots' => [],
        ];

        $patterns = [
            'code' => '/@widget\.code\s+([^\r\n]+)/i',
            'name' => '/@widget\.name\s+([^\r\n]+)/i',
            'description' => '/@widget\.description\s+([^\r\n]+)/i',
            'type' => '/@widget\.type\s+([^\r\n]+)/i',
            'area' => '/@widget\.area\s+([^\r\n]+)/i',
            'slot' => '/@widget\.slot\s+([^\r\n]+)/i',
            'icon' => '/@widget\.icon\s+([^\r\n]+)/i',
            'page_layouts' => '/@widget\.page_layouts\s+([^\r\n]+)/i',
            'position' => '/@widget\.position\s+([^\r\n]+)/i',
            'supports' => '/@widget\.supports\s+([^\r\n]+)/i',
        ];

        foreach ($patterns as $key => $pattern) {
            if (preg_match($pattern, $content, $matches)) {
                $data[$key] = trim((string)$matches[1]);
            }
        }

        if (preg_match('/@widget\.exclusive\s+\{?(true|false|1|0)\}?/i', $content, $matches)) {
            $data['exclusive'] = $this->toBool((string)$matches[1]);
        }
        if (preg_match('/@widget\.compatible\s+\{?(true|false|1|0)\}?/i', $content, $matches)) {
            $data['compatible'] = $this->toBool((string)$matches[1]);
        }
        if (preg_match('/@widget\.is_container\s+\{?(true|false|1|0)\}?/i', $content, $matches)) {
            $data['is_container'] = $this->toBool((string)$matches[1]);
        }

        foreach (['code', 'name', 'description', 'type', 'area', 'slot', 'icon'] as $key) {
            $data[$key] = $this->unwrapWidgetScalar((string)$data[$key]);
        }

        $data['position'] = $this->parseWidgetListValue($data['position']);
        $data['supports'] = $this->parseWidgetListValue($data['supports']);
        $data['page_layouts'] = $this->parseWidgetListValue($data['page_layouts']) ?: ['*'];
        $slots = $this->extractWidgetBraceValue($content, 'slots');
        $data['slots'] = is_array($slots) ? $slots : [];

        return $data;
    }

    private function extractWidgetBraceValue(string $content, string $key): mixed
    {
        $pattern = '/@widget\.' . preg_quote($key, '/') . '\s*\{/i';
        if (!preg_match($pattern, $content, $match, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $matchPos = (int)$match[0][1];
        $bracePos = strpos($content, '{', $matchPos);
        if ($bracePos === false) {
            return null;
        }

        $level = 0;
        $quote = null;
        $escaped = false;
        $len = strlen($content);
        for ($i = $bracePos; $i < $len; $i++) {
            $ch = $content[$i];
            if ($quote !== null) {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($ch === '\\') {
                    $escaped = true;
                    continue;
                }
                if ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $quote = $ch;
                continue;
            }
            if ($ch === '{') {
                $level++;
            } elseif ($ch === '}') {
                $level--;
                if ($level === 0) {
                    $raw = trim(substr($content, $bracePos + 1, $i - $bracePos - 1));
                    if ($raw === '') {
                        return null;
                    }
                    if (!str_starts_with($raw, '{') && !str_starts_with($raw, '[')) {
                        $raw = '{' . $raw . '}';
                    }
                    $decoded = json_decode($raw, true);
                    return is_array($decoded) ? $decoded : null;
                }
            }
        }

        return null;
    }

    private function extractMeta(string $content, array $parsed, string $fallbackName): array
    {
        $meta = [
            'name' => $fallbackName,
            'description' => '',
            'icon' => '',
            'preview_url' => '',
        ];

        foreach (['name', 'description', 'icon', 'preview_url'] as $key) {
            $parsedNode = $parsed['meta'][$key] ?? null;
            if (is_array($parsedNode)) {
                $value = $parsedNode['default'] ?? $parsedNode['name'] ?? '';
                if ($value !== '') {
                    $meta[$key] = (string)$value;
                }
            }
            if (preg_match('/@meta\.' . preg_quote($key, '/') . '\s*\{[^}]*default=["\']([^"\']+)["\']/', $content, $matches)) {
                $meta[$key] = trim((string)$matches[1]);
            }
        }

        return $meta;
    }

    private function formatParams(array $params): array
    {
        /** @var ParamDefinitionNormalizer $normalizer */
        $normalizer = ObjectManager::getInstance(ParamDefinitionNormalizer::class);
        return $normalizer->normalizeParsedParamList($params);
    }

    private function extractSlots(string $content, string $area, string $filePath): array
    {
        $slots = [];
        if (preg_match_all('/<w:slot\b([^>]*)>/i', $content, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $attributes = $this->parseHtmlAttributes((string)$match[1]);
                $slotId = $attributes['id'] ?? '';
                if ($slotId === '' || $this->isDynamicSlotId($slotId) || isset($slots[$slotId])) {
                    continue;
                }
                $slots[$slotId] = new ThemeSlotDefinition(
                    $slotId,
                    (string)($attributes['name'] ?? $slotId),
                    $area,
                    $this->splitCsv((string)($attributes['accept'] ?? '')),
                    $this->toBool((string)($attributes['exclusive'] ?? 'false')),
                    $this->toBool((string)($attributes['multiple'] ?? 'true'), true),
                    $this->toBool((string)($attributes['append'] ?? 'false')),
                    $this->toBool((string)($attributes['prepend'] ?? 'false')),
                    [
                        'position' => $attributes['position'] ?? null,
                        'reject' => $this->splitCsv((string)($attributes['reject'] ?? '')),
                        'max' => $this->normalizeOptionalInt($attributes['max'] ?? null),
                        'min' => $this->normalizeOptionalInt($attributes['min'] ?? null),
                        'required' => $this->toBool((string)($attributes['required'] ?? 'false')),
                    ],
                    $filePath,
                );
            }
        }

        if (preg_match_all('/<[^>]+\sdata-wslot=(["\'])(.*?)\1[^>]*>/i', $content, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $index => $fullMatch) {
                $attributes = $this->parseHtmlAttributes((string)$fullMatch[0]);
                $slotId = $attributes['data-wslot'] ?? '';
                if ($slotId === '' || $this->isDynamicSlotId($slotId) || isset($slots[$slotId])) {
                    continue;
                }
                $slots[$slotId] = new ThemeSlotDefinition(
                    $slotId,
                    (string)($attributes['data-wslot-name'] ?? $slotId),
                    $area,
                    $this->splitCsv((string)($attributes['data-wslot-accept'] ?? '')),
                    $this->toBool((string)($attributes['data-wslot-exclusive'] ?? 'false')),
                    $this->toBool((string)($attributes['data-wslot-multiple'] ?? 'true'), true),
                    $this->toBool((string)($attributes['data-wslot-append'] ?? 'false')),
                    $this->toBool((string)($attributes['data-wslot-prepend'] ?? 'false')),
                    [
                        'position' => $attributes['data-wslot-position'] ?? null,
                        'reject' => $this->splitCsv((string)($attributes['data-wslot-reject'] ?? '')),
                        'max' => $this->normalizeOptionalInt($attributes['data-wslot-max'] ?? null),
                        'min' => $this->normalizeOptionalInt($attributes['data-wslot-min'] ?? null),
                        'required' => $this->toBool((string)($attributes['data-wslot-required'] ?? 'false')),
                    ],
                    $filePath,
                );
            }
        }

        return array_values(array_map(static fn(ThemeSlotDefinition $slot): array => $slot->toArray(), $slots));
    }

    private function isDynamicSlotId(string $slotId): bool
    {
        return str_contains($slotId, '<?')
            || str_contains($slotId, '$')
            || str_contains($slotId, '{layout_id}')
            || str_contains($slotId, '{tab_key}');
    }

    private function parseHtmlAttributes(string $html): array
    {
        $attributes = [];
        if (preg_match_all('/([a-zA-Z_:][-a-zA-Z0-9_:.]*)\s*=\s*(["\'])(.*?)\2/', $html, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $attributes[strtolower((string)$match[1])] = (string)$match[3];
            }
        }

        return $attributes;
    }

    private function normalizeOptionalInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int)$value : null;
    }

    private function splitCsv(string|array $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map('strval', $value), static fn(string $item): bool => $item !== ''));
        }

        return array_values(array_filter(array_map(
            static fn(string $item): string => trim($item),
            explode(',', $value)
        ), static fn(string $item): bool => $item !== ''));
    }

    private function unwrapWidgetScalar(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        if (str_starts_with($value, '{') && str_ends_with($value, '}')) {
            $value = trim(substr($value, 1, -1));
        }

        return trim($value, " \t\n\r\0\x0B\"'");
    }

    private function parseWidgetListValue(string|array $value): array
    {
        if (is_array($value)) {
            return $this->splitCsv($value);
        }

        $value = $this->unwrapWidgetScalar($value);
        if ($value === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return $this->splitCsv($decoded);
        }

        return $this->splitCsv($value);
    }

    private function toBool(string $value, bool $default = false): bool
    {
        if ($value === '') {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    private function normalizeType(string $type): string
    {
        return strtolower(trim($type));
    }

    private function normalizeArea(string $area): string
    {
        return strtolower(trim($area)) === 'backend' ? 'backend' : 'frontend';
    }

    private function buildCacheKey(string $type, string $area, ?WelineTheme $theme, string $scope): string
    {
        $themeId = $theme?->getId() ?: 0;
        return "{$scope}:{$type}:{$area}:{$themeId}";
    }
}

<?php
declare(strict_types=1);

namespace Weline\SystemConfig\Service;

use Weline\Framework\Extends\ExtendsData;

class SystemConfigTemplateService
{
    private const TARGET_MODULE = 'Weline_SystemConfig';
    private const CONFIG_PREFIX = 'extends/module/Weline_SystemConfig/Config/';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getTemplates(?string $module = null, ?string $area = null, bool $forceReload = false): array
    {
        $templates = [];
        foreach ($this->getRegisteredTemplateExtensions($forceReload) as $extension) {
            $summary = $this->buildTemplateSummary($extension);
            if ($summary === null) {
                continue;
            }
            if ($module !== null && $module !== '' && $summary['module'] !== $module) {
                continue;
            }
            if ($area !== null && $area !== '' && $summary['area'] !== $area) {
                continue;
            }
            $templates[] = $summary;
        }

        usort($templates, static function (array $left, array $right): int {
            return [$left['sort'], $left['module'], $left['area'], $left['code']]
                <=> [$right['sort'], $right['module'], $right['area'], $right['code']];
        });

        return $templates;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getTemplateMeta(string $module, string $area, string $code, bool $forceReload = false): ?array
    {
        $module = trim($module);
        $area = trim($area);
        $code = $this->normalizeCode($code);
        if ($module === '' || $area === '' || $code === '') {
            return null;
        }

        foreach ($this->getRegisteredTemplateExtensions($forceReload) as $extension) {
            $summary = $this->buildTemplateSummary($extension);
            if ($summary === null) {
                continue;
            }
            if ($summary['module'] !== $module || $summary['area'] !== $area || $summary['code'] !== $code) {
                continue;
            }

            return $this->parseTemplate($extension, $summary);
        }

        return null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getModules(?string $area = null, ?string $search = null, bool $forceReload = false): array
    {
        $modules = [];
        foreach ($this->getTemplates(area: $area, forceReload: $forceReload) as $template) {
            $module = (string)$template['module'];
            if (!isset($modules[$module])) {
                $modules[$module] = [
                    'module' => $module,
                    'label' => $module,
                    'template_count' => 0,
                    'areas' => [],
                    'sort' => (int)$template['sort'],
                ];
            }
            $modules[$module]['template_count']++;
            $modules[$module]['areas'][(string)$template['area']] = true;
            $modules[$module]['sort'] = min((int)$modules[$module]['sort'], (int)$template['sort']);
        }

        $items = array_values(array_map(static function (array $item): array {
            $item['areas'] = array_keys($item['areas']);
            sort($item['areas']);
            return $item;
        }, $modules));

        if ($search !== null && trim($search) !== '') {
            $needle = mb_strtolower(trim($search));
            $items = array_values(array_filter($items, static function (array $item) use ($needle): bool {
                return str_contains(mb_strtolower((string)$item['module']), $needle)
                    || str_contains(mb_strtolower((string)$item['label']), $needle);
            }));
        }

        usort($items, static function (array $left, array $right): int {
            return [$left['sort'], $left['module']] <=> [$right['sort'], $right['module']];
        });

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    public function getTree(?string $module = null, ?string $area = null, ?string $search = null, bool $forceReload = false): array
    {
        $templates = $this->getTemplates(module: $module, area: $area, forceReload: $forceReload);
        $tree = [
            'target_module' => self::TARGET_MODULE,
            'modules' => [],
        ];
        $needle = $search !== null ? mb_strtolower(trim($search)) : '';

        foreach ($templates as $template) {
            $meta = $this->getTemplateMeta(
                (string)$template['module'],
                (string)$template['area'],
                (string)$template['code'],
                $forceReload
            );
            if ($meta === null) {
                continue;
            }
            if ($needle !== '' && !$this->templateMatchesSearch($meta, $needle)) {
                continue;
            }

            $moduleName = (string)$template['module'];
            if (!isset($tree['modules'][$moduleName])) {
                $tree['modules'][$moduleName] = [
                    'module' => $moduleName,
                    'areas' => [],
                    'template_count' => 0,
                    'field_count' => 0,
                    'adapter_count' => 0,
                ];
            }

            $areaName = (string)$template['area'];
            if (!isset($tree['modules'][$moduleName]['areas'][$areaName])) {
                $tree['modules'][$moduleName]['areas'][$areaName] = [
                    'area' => $areaName,
                    'templates' => [],
                ];
            }

            $tree['modules'][$moduleName]['areas'][$areaName]['templates'][] = $meta;
            $tree['modules'][$moduleName]['template_count']++;
            $tree['modules'][$moduleName]['field_count'] += count($meta['fields']);
            $tree['modules'][$moduleName]['adapter_count'] += count($meta['adapters']);
        }

        $tree['modules'] = array_values(array_map(static function (array $module): array {
            $module['areas'] = array_values($module['areas']);
            return $module;
        }, $tree['modules']));

        return $tree;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function getRegisteredTemplateExtensions(bool $forceReload = false): array
    {
        $registered = [];
        foreach (ExtendsData::getExtendedBy(self::TARGET_MODULE, $forceReload) as $sourceModule => $extensions) {
            if (!is_array($extensions)) {
                continue;
            }
            foreach ($extensions as $extension) {
                if (!is_array($extension)) {
                    continue;
                }
                $extension['source_module'] = (string)($extension['source_module'] ?? $sourceModule);
                $relativePath = str_replace('\\', '/', (string)($extension['relative_path'] ?? ''));
                if (!str_starts_with($relativePath, self::CONFIG_PREFIX) || !str_ends_with($relativePath, '.phtml')) {
                    continue;
                }
                $registered[] = $extension;
            }
        }

        return $registered;
    }

    /**
     * @param array<string, mixed> $extension
     * @return array<string, mixed>|null
     */
    private function buildTemplateSummary(array $extension): ?array
    {
        $relativePath = str_replace('\\', '/', (string)($extension['relative_path'] ?? ''));
        $sourceFile = (string)($extension['source_file'] ?? '');
        if ($relativePath === '' || $sourceFile === '' || !is_file($sourceFile)) {
            return null;
        }

        $suffix = substr($relativePath, strlen(self::CONFIG_PREFIX));
        $parts = explode('/', $suffix);
        if (count($parts) < 2) {
            return null;
        }

        $area = trim((string)$parts[0]);
        $code = $this->normalizeCode((string)end($parts));
        if ($area === '' || $code === '') {
            return null;
        }

        $source = $this->readTemplateSource($sourceFile);
        $header = $this->parseHeaderMeta($source);
        $config = is_array($header['config'] ?? null) ? $header['config'] : [];
        $meta = is_array($header['meta'] ?? null) ? $header['meta'] : [];

        if (!empty($config['area'])) {
            $area = (string)$config['area'];
        }

        return [
            'module' => (string)($extension['source_module'] ?? ''),
            'area' => $area,
            'code' => $code,
            'title' => $this->metaDefault($meta['title'] ?? null) ?: $code,
            'description' => $this->metaDefault($meta['description'] ?? null),
            'sort' => (int)($config['sort'] ?? 0),
            'acl' => (string)($config['acl'] ?? ''),
            'relative_path' => $relativePath,
            'source_file' => $sourceFile,
            'mtime' => filemtime($sourceFile) ?: 0,
        ];
    }

    /**
     * @param array<string, mixed> $extension
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function parseTemplate(array $extension, array $summary): array
    {
        $sourceFile = (string)($extension['source_file'] ?? '');
        $source = $this->readTemplateSource($sourceFile);
        $header = $this->parseHeaderMeta($source);
        $tags = $this->parseConfigTags($source);

        return array_merge($summary, [
            'meta' => is_array($header['meta'] ?? null) ? $header['meta'] : [],
            'config' => is_array($header['config'] ?? null) ? $header['config'] : [],
            'groups' => $tags['groups'],
            'fields' => $tags['fields'],
            'adapters' => $tags['adapters'],
            'hints' => $tags['hints'],
            'field_keys' => array_values(array_map(static fn(array $field): string => (string)$field['key'], $tags['fields'])),
        ]);
    }

    private function readTemplateSource(string $sourceFile): string
    {
        $content = file_get_contents($sourceFile);
        return is_string($content) ? $content : '';
    }

    /**
     * @return array{meta: array<string, mixed>, config: array<string, mixed>}
     */
    private function parseHeaderMeta(string $source): array
    {
        $result = ['meta' => [], 'config' => []];
        if (!preg_match_all('/@(meta|config)\.([a-zA-Z0-9_.-]+)\s*\{([^}]*)\}/u', $source, $matches, PREG_SET_ORDER)) {
            return $result;
        }

        foreach ($matches as $match) {
            $section = (string)$match[1];
            $key = (string)$match[2];
            $body = trim((string)$match[3]);
            $parsed = $this->parseBraceValue($body);
            $result[$section][$key] = $parsed;
        }

        return $result;
    }

    /**
     * @return string|array<string, string>
     */
    private function parseBraceValue(string $body): string|array
    {
        $attrs = $this->parseAttributes($body);
        if ($attrs !== []) {
            return $attrs;
        }

        return trim($body, " \t\n\r\0\x0B\"'");
    }

    /**
     * @return array{groups: array<int, array<string, mixed>>, fields: array<int, array<string, mixed>>, adapters: array<int, array<string, mixed>>, hints: array<int, array<string, mixed>>}
     */
    private function parseConfigTags(string $source): array
    {
        $result = ['groups' => [], 'fields' => [], 'adapters' => [], 'hints' => []];
        $groupStack = [];

        if (!preg_match_all('/<\/w:config:group\s*>|<w:config:(group|field|adapter|hint)\b([^>]*)>/iu', $source, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            return $result;
        }

        foreach ($matches as $match) {
            $raw = (string)$match[0][0];
            if (str_starts_with(strtolower($raw), '</w:config:group')) {
                array_pop($groupStack);
                continue;
            }

            $type = strtolower((string)$match[1][0]);
            $attrs = $this->parseAttributes((string)$match[2][0]);
            $currentGroup = $groupStack === [] ? '' : (string)end($groupStack);
            if ($currentGroup !== '' && $type !== 'group') {
                $attrs['group'] = $currentGroup;
            }

            if ($type === 'group') {
                $code = (string)($attrs['code'] ?? '');
                $result['groups'][] = $attrs;
                if (!str_ends_with(rtrim($raw), '/>')) {
                    $groupStack[] = $code;
                }
                continue;
            }

            if ($type === 'field') {
                $result['fields'][] = $attrs;
            } elseif ($type === 'adapter') {
                $result['adapters'][] = $attrs;
            } elseif ($type === 'hint') {
                if (!str_ends_with(rtrim($raw), '/>')) {
                    $bodyStart = (int)$match[0][1] + strlen($raw);
                    $bodyEnd = stripos($source, '</w:config:hint>', $bodyStart);
                    if ($bodyEnd !== false) {
                        $body = trim(substr($source, $bodyStart, $bodyEnd - $bodyStart));
                        $body = preg_replace('/<\?(?:php)?[\s\S]*?\?>/i', '', $body) ?? $body;
                        $body = trim(html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                        if ($body !== '') {
                            $attrs['text'] = $body;
                        }
                    }
                }
                if (($attrs['text'] ?? '') === '' && ($attrs['description'] ?? '') !== '') {
                    $attrs['text'] = (string)$attrs['description'];
                }
                $result['hints'][] = $attrs;
            }
        }

        return $result;
    }

    /**
     * @return array<string, string>
     */
    private function parseAttributes(string $source): array
    {
        $attrs = [];
        if (!preg_match_all('/([a-zA-Z0-9_:-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))/u', $source, $matches, PREG_SET_ORDER)) {
            return [];
        }

        foreach ($matches as $match) {
            $value = $match[2] ?? $match[3] ?? $match[4] ?? '';
            $attrs[(string)$match[1]] = html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        return $attrs;
    }

    private function normalizeCode(string $code): string
    {
        $code = trim(str_replace('\\', '/', $code));
        $base = basename($code);
        return preg_replace('/\.phtml$/i', '', $base) ?: '';
    }

    private function metaDefault(mixed $value): string
    {
        if (is_array($value)) {
            return (string)($value['default'] ?? '');
        }

        return (string)($value ?? '');
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function templateMatchesSearch(array $meta, string $needle): bool
    {
        $haystack = [
            (string)($meta['module'] ?? ''),
            (string)($meta['area'] ?? ''),
            (string)($meta['code'] ?? ''),
            (string)($meta['title'] ?? ''),
            (string)($meta['description'] ?? ''),
        ];
        foreach (['groups', 'fields', 'adapters', 'hints'] as $collection) {
            foreach (($meta[$collection] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                foreach (['code', 'key', 'label', 'description', 'text'] as $key) {
                    $haystack[] = (string)($item[$key] ?? '');
                }
            }
        }

        return str_contains(mb_strtolower(implode(' ', $haystack)), $needle);
    }
}

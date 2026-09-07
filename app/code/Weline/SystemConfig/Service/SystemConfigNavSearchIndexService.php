<?php
declare(strict_types=1);

namespace Weline\SystemConfig\Service;

use Weline\Framework\Http\Request;
use Weline\Framework\Manager\ObjectManager;

/**
 * Flatten SystemConfig template fields into sidebar nav-filter search hits.
 */
final class SystemConfigNavSearchIndexService
{
    private const CACHE_TTL = 60.0;

    /**
     * @var array{expires: float, fingerprint: string, items: list<array<string, string>>}|null
     */
    private static ?array $cache = null;

    public function __construct(
        private readonly SystemConfigTemplateService $templateService,
    ) {
    }

    /**
     * @return list<array{
     *   key: string,
     *   label: string,
     *   description: string,
     *   module: string,
     *   area: string,
     *   template_title: string,
     *   template_code: string,
     *   url: string,
     *   search_text: string
     * }>
     */
    public function getItems(bool $forceReload = false): array
    {
        $summaries = $this->templateService->getTemplates(forceReload: $forceReload);
        $fingerprint = $this->fingerprint($summaries);
        $now = microtime(true);
        if (
            !$forceReload
            && self::$cache !== null
            && self::$cache['expires'] > $now
            && self::$cache['fingerprint'] === $fingerprint
        ) {
            return self::$cache['items'];
        }

        $items = [];
        $seenKeys = [];
        foreach ($summaries as $summary) {
            if (!is_array($summary)) {
                continue;
            }
            $module = trim((string)($summary['module'] ?? ''));
            $area = trim((string)($summary['area'] ?? ''));
            $code = trim((string)($summary['code'] ?? ''));
            if ($module === '' || $area === '' || $code === '') {
                continue;
            }
            $meta = $this->templateService->getTemplateMeta($module, $area, $code, $forceReload);
            if ($meta === null) {
                continue;
            }
            $templateTitle = trim((string)($meta['title'] ?? $code));
            foreach (($meta['fields'] ?? []) as $field) {
                if (!is_array($field)) {
                    continue;
                }
                $key = trim((string)($field['key'] ?? ''));
                if ($key === '' || isset($seenKeys[$key])) {
                    continue;
                }
                $url = $this->configCenterDeeplink($module, $area, $key);
                if ($url === '') {
                    continue;
                }
                $label = trim((string)($field['label'] ?? ''));
                if ($label === '') {
                    $label = $key;
                }
                $description = trim((string)($field['description'] ?? ''));
                $searchText = mb_strtolower(trim(implode(' ', array_filter([
                    $label,
                    $key,
                    $description,
                    $module,
                    $area,
                    $code,
                    $templateTitle,
                    (string)($field['group'] ?? ''),
                ], static fn(string $part): bool => $part !== ''))));

                $seenKeys[$key] = true;
                $items[] = [
                    'key' => $key,
                    'label' => $label,
                    'description' => $description,
                    'module' => $module,
                    'area' => $area,
                    'template_title' => $templateTitle,
                    'template_code' => $code,
                    'url' => $url,
                    'search_text' => $searchText,
                ];
            }
        }

        usort($items, static function (array $left, array $right): int {
            return [$left['module'], $left['template_code'], $left['label'], $left['key']]
                <=> [$right['module'], $right['template_code'], $right['label'], $right['key']];
        });

        self::$cache = [
            'expires' => $now + self::CACHE_TTL,
            'fingerprint' => $fingerprint,
            'items' => $items,
        ];

        return $items;
    }

    /**
     * @param list<array<string, mixed>> $summaries
     */
    private function fingerprint(array $summaries): string
    {
        $parts = [];
        foreach ($summaries as $summary) {
            if (!is_array($summary)) {
                continue;
            }
            $parts[] = (string)($summary['module'] ?? '')
                . '|' . (string)($summary['area'] ?? '')
                . '|' . (string)($summary['code'] ?? '')
                . '|' . (string)($summary['mtime'] ?? '0');
        }
        sort($parts);

        return hash('sha256', implode("\n", $parts));
    }

    private function configCenterDeeplink(string $module, string $area, string $key): string
    {
        try {
            /** @var Request $request */
            $request = ObjectManager::getInstance(Request::class);

            return (string)$request->getUrlBuilder()->getBackendUrl('weline_systemconfig/backend/config', [
                'module' => $module,
                'area' => $area,
                'guide_key' => $key,
                'guide_locate' => $key,
            ]);
        } catch (\Throwable) {
            return '';
        }
    }
}

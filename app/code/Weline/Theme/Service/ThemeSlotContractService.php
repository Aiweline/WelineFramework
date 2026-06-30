<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Theme\Model\WelineTheme;

class ThemeSlotContractService
{
    private const RESOURCE_TYPES = ['layouts', 'partials', 'components', 'widgets'];

    public function __construct(
        private readonly ThemeResourceCatalog $resourceCatalog,
    ) {
    }

    /**
     * Find effective theme overrides that dropped slots provided by Weline_Theme defaults.
     */
    public function collectMissingDefaultSlots(string $area = 'frontend', ?WelineTheme $theme = null): array
    {
        $area = $this->normalizeArea($area);
        $warnings = [];

        foreach (self::RESOURCE_TYPES as $type) {
            $defaults = [];
            $themeOverrides = [];

            foreach ($this->resourceCatalog->getRawResources($type, $area, $theme) as $resource) {
                $logicalKey = (string)($resource['logical_key'] ?? '');
                if ($logicalKey === '') {
                    continue;
                }

                $layerType = (string)($resource['layer_type'] ?? '');
                if ($layerType === 'default') {
                    $defaults[$logicalKey] ??= $resource;
                    continue;
                }

                if ($layerType === 'theme') {
                    $themeOverrides[$logicalKey] ??= $resource;
                }
            }

            foreach ($themeOverrides as $logicalKey => $themeResource) {
                $defaultResource = $defaults[$logicalKey] ?? null;
                if ($defaultResource === null) {
                    continue;
                }

                $defaultSlots = $this->slotsById((array)($defaultResource['slots'] ?? []));
                if (empty($defaultSlots)) {
                    continue;
                }

                $themeSlots = $this->slotsById((array)($themeResource['slots'] ?? []));
                $missingIds = array_values(array_diff(array_keys($defaultSlots), array_keys($themeSlots)));
                if (empty($missingIds)) {
                    continue;
                }

                $missingSlots = [];
                foreach ($missingIds as $slotId) {
                    $missingSlots[] = $defaultSlots[$slotId];
                }

                $relativePath = (string)($themeResource['relative_path'] ?? $logicalKey);
                $warnings[] = [
                    'type' => $type,
                    'area' => $area,
                    'logical_key' => $logicalKey,
                    'theme_id' => (int)($themeResource['theme_id'] ?? 0),
                    'theme_name' => (string)($themeResource['theme_name'] ?? ''),
                    'file' => (string)($themeResource['file_path'] ?? ''),
                    'relative_path' => $relativePath,
                    'default_file' => (string)($defaultResource['file_path'] ?? ''),
                    'missing_slot_ids' => $missingIds,
                    'missing_slots' => $missingSlots,
                    'message' => sprintf(
                        'Theme override %s is missing default slot(s): %s',
                        $relativePath,
                        implode(', ', $missingIds)
                    ),
                ];
            }
        }

        return $warnings;
    }

    public function notifyMissingDefaultSlots(array $warnings, string $area = 'frontend'): void
    {
        if (empty($warnings) || !defined('DEV') || !DEV || !function_exists('w_msg')) {
            return;
        }

        static $sent = [];
        $signature = $this->buildWarningSignature($warnings, $area);
        if (isset($sent[$signature])) {
            return;
        }
        $sent[$signature] = true;

        \w_msg(
            'theme_slot_contract_missing',
            'warning',
            '主题 slot 缺失',
            $this->buildNotificationContent($warnings),
            [
                'priority' => 6,
                'icon' => 'ri-layout-masonry-line',
                'source_module' => 'Weline_Theme',
                'metadata' => [
                    'area' => $this->normalizeArea($area),
                    'warning_count' => count($warnings),
                ],
            ]
        );

        if (function_exists('w_log_warning')) {
            foreach ($warnings as $warning) {
                \w_log_warning('[Theme Slot Missing] ' . (string)($warning['message'] ?? 'Unknown missing slot'));
            }
        }
    }

    public function injectMissingSlotWarningHtml(string $html, array $warnings): string
    {
        $warningHtml = $this->renderMissingSlotWarningHtml($warnings);
        if ($warningHtml === '' || stripos($html, 'id="theme-slot-contract-warning"') !== false) {
            return $html;
        }

        if (stripos($html, '</body>') !== false) {
            return (string)preg_replace('/<\/body>/i', $warningHtml . '</body>', $html, 1);
        }

        return $html . $warningHtml;
    }

    public function renderMissingSlotWarningHtml(array $warnings): string
    {
        if (empty($warnings)) {
            return '';
        }

        $items = [];
        foreach (array_slice($warnings, 0, 8) as $warning) {
            $resource = $this->escape((string)($warning['logical_key'] ?? $warning['relative_path'] ?? 'unknown'));
            $ids = array_map(fn($slotId): string => '<code>' . $this->escape((string)$slotId) . '</code>', (array)($warning['missing_slot_ids'] ?? []));
            $items[] = '<li><strong>' . $resource . '</strong>: ' . implode(', ', $ids) . '</li>';
        }

        $remaining = count($warnings) - count($items);
        if ($remaining > 0) {
            $items[] = '<li>还有 ' . $remaining . ' 个覆写文件缺少默认 slot。</li>';
        }

        $warningItems = implode("\n", $items);

        return <<<HTML
<div id="theme-slot-contract-warning" style="
    position: fixed;
    bottom: 20px;
    right: 20px;
    max-width: 440px;
    background: #fff7ed;
    border: 1px solid #fb923c;
    border-radius: 8px;
    padding: 15px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    z-index: 10001;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    font-size: 14px;
">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
        <strong style="color: #9a3412;">默认 slot 缺失</strong>
        <button type="button" data-theme-slot-warning-dismiss style="
            background: none;
            border: none;
            font-size: 18px;
            cursor: pointer;
            color: #9a3412;
        ">&times;</button>
    </div>
    <p style="margin: 0 0 10px 0; color: #9a3412;">
        当前主题覆写了 Weline_Theme 默认文件，但缺少默认 slot。依赖这些 slot 的插件可能无法挂载或编辑。
    </p>
    <ul style="margin: 0; padding-left: 20px; color: #9a3412;">
        {$warningItems}
    </ul>
</div>
<script>
document.addEventListener('click', function(event) {
    if (event.target.closest('[data-theme-slot-warning-dismiss]')) {
        const panel = document.getElementById('theme-slot-contract-warning');
        if (panel) {
            panel.remove();
        }
    }
});
</script>
HTML;
    }

    private function slotsById(array $slots): array
    {
        $indexed = [];
        foreach ($slots as $slot) {
            if (!is_array($slot)) {
                continue;
            }

            $slotId = trim((string)($slot['id'] ?? ''));
            if ($slotId === '') {
                continue;
            }

            $meta = is_array($slot['meta'] ?? null) ? $slot['meta'] : [];
            $indexed[$slotId] = [
                'id' => $slotId,
                'name' => (string)($slot['name'] ?? $slotId),
                'accept' => $this->normalizeAccept($slot['accept'] ?? []),
                'reject' => $this->normalizeAccept($slot['reject'] ?? $meta['reject'] ?? []),
                'exclusive' => (bool)($slot['exclusive'] ?? false),
                'multiple' => (bool)($slot['multiple'] ?? true),
                'position' => (string)($slot['position'] ?? $meta['position'] ?? ''),
                'max' => $slot['max'] ?? $meta['max'] ?? null,
                'min' => $slot['min'] ?? $meta['min'] ?? null,
                'required' => (bool)($slot['required'] ?? $meta['required'] ?? false),
            ];
        }

        return $indexed;
    }

    private function normalizeAccept(mixed $accept): array
    {
        if (is_array($accept)) {
            return array_values(array_filter(array_map('strval', $accept), static fn(string $item): bool => $item !== ''));
        }

        return array_values(array_filter(array_map(
            static fn(string $item): string => trim($item),
            explode(',', (string)$accept)
        ), static fn(string $item): bool => $item !== ''));
    }

    private function buildNotificationContent(array $warnings): string
    {
        $lines = [
            '当前主题覆写了 Weline_Theme 默认文件，但缺少默认 slot。相关插件可能找不到挂载点。',
        ];

        foreach (array_slice($warnings, 0, 8) as $warning) {
            $lines[] = sprintf(
                '- %s: %s',
                (string)($warning['logical_key'] ?? $warning['relative_path'] ?? 'unknown'),
                implode(', ', (array)($warning['missing_slot_ids'] ?? []))
            );
        }

        $remaining = count($warnings) - 8;
        if ($remaining > 0) {
            $lines[] = sprintf('- 另有 %d 个覆写文件存在相同问题。', $remaining);
        }

        return implode("\n", $lines);
    }

    private function buildWarningSignature(array $warnings, string $area): string
    {
        $payload = [];
        foreach ($warnings as $warning) {
            $payload[] = [
                'logical_key' => (string)($warning['logical_key'] ?? ''),
                'missing_slot_ids' => array_values((array)($warning['missing_slot_ids'] ?? [])),
            ];
        }

        return $this->normalizeArea($area) . ':' . md5((string)json_encode($payload));
    }

    private function normalizeArea(string $area): string
    {
        return strtolower(trim($area)) === 'backend' ? 'backend' : 'frontend';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Framework\Manager\ObjectManager;
use Weline\Theme\Helper\ThemeData;
use Weline\Theme\Model\WelineTheme;

/**
 * Create / list product layout_option shells from file layouts (no theme_virtual_layout table).
 */
final class ProductLayoutOptionService
{
    public const LAYOUT_TYPE = 'product';

    public function __construct(
        private readonly ThemeResourceCatalog $catalog,
        private readonly ThemeVirtualLayoutService $virtualLayout,
    ) {
    }

    /**
     * @return list<array{value:string,label:string,description:string,file:string,source:string}>
     */
    public function listProductOptions(string $area = 'frontend', ?int $themeId = null): array
    {
        $theme = $this->resolveTheme($area, $themeId);
        $byType = $this->catalog->getLayouts($area, $theme);
        $rows = is_array($byType[self::LAYOUT_TYPE] ?? null) ? $byType[self::LAYOUT_TYPE] : [];
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $value = $this->virtualLayout->normalizeLayoutOption((string)($row['value'] ?? ''));
            if ($value === '') {
                continue;
            }
            $meta = is_array($row['meta'] ?? null) ? $row['meta'] : [];
            $label = trim((string)($meta['name'] ?? $meta['title'] ?? ''));
            if ($label === '') {
                $label = $value === 'default' ? (string)__('默认') : $value;
            }
            $out[] = [
                'value' => $value,
                'label' => $label,
                'description' => trim((string)($meta['description'] ?? '')),
                'file' => (string)($row['file'] ?? ''),
                'source' => 'file',
            ];
        }
        usort($out, static fn(array $a, array $b): int => strcmp($a['label'], $b['label']));

        return $out;
    }

    /**
     * Clone a product layout file shell to a new layout_option under Theme module layouts.
     *
     * @return array<string,mixed>
     */
    public function createOption(
        string $layoutOption,
        string $displayName = '',
        string $cloneFrom = 'default',
        string $area = 'frontend',
    ): array {
        $layoutOption = $this->virtualLayout->normalizeLayoutOption($layoutOption);
        $cloneFrom = $this->virtualLayout->normalizeLayoutOption($cloneFrom);
        if ($cloneFrom === '') {
            $cloneFrom = 'default';
        }
        if ($layoutOption === '' || !preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $layoutOption)) {
            return [
                'success' => false,
                'status' => 'invalid_layout_option',
                'message' => (string)__('布局代码须为小写字母开头的字母数字下划线短横线'),
            ];
        }
        if (in_array($layoutOption, ['default'], true)) {
            return [
                'success' => false,
                'status' => 'reserved_layout_option',
                'message' => (string)__('不能覆盖保留的 default 布局'),
            ];
        }

        $existing = $this->listProductOptions($area);
        foreach ($existing as $row) {
            if ($row['value'] === $layoutOption) {
                return [
                    'success' => false,
                    'status' => 'layout_option_exists',
                    'message' => (string)__('布局选项已存在'),
                    'layout_option' => $layoutOption,
                ];
            }
        }

        $sourcePath = $this->resolveLayoutFilePath($area, $cloneFrom)
            ?? $this->resolveLayoutFilePath($area, 'default');
        if ($sourcePath === null || !is_file($sourcePath)) {
            return [
                'success' => false,
                'status' => 'clone_source_missing',
                'message' => (string)__('找不到可克隆的产品布局模板'),
            ];
        }

        $targetDir = $this->moduleLayoutsDirectory($area);
        if (!is_dir($targetDir) && !@mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
            return [
                'success' => false,
                'status' => 'target_dir_unwritable',
                'message' => (string)__('无法创建布局目录'),
            ];
        }
        $targetPath = $targetDir . DIRECTORY_SEPARATOR . $layoutOption . '.phtml';
        if (is_file($targetPath)) {
            return [
                'success' => false,
                'status' => 'layout_option_exists',
                'message' => (string)__('布局文件已存在'),
                'layout_option' => $layoutOption,
            ];
        }

        $source = (string)file_get_contents($sourcePath);
        $name = trim($displayName) !== '' ? trim($displayName) : $this->humanize($layoutOption);
        $source = $this->rewriteClonedShell($source, $layoutOption, $name, $cloneFrom);
        if (@file_put_contents($targetPath, $source) === false) {
            return [
                'success' => false,
                'status' => 'write_failed',
                'message' => (string)__('写入布局文件失败'),
            ];
        }

        ThemeData::clearCache();
        ObjectManager::getInstance(ThemeResourceCatalog::class);

        $workspaceWarmed = $this->warmScopedWorkspace($layoutOption, $area);

        return [
            'success' => true,
            'status' => 'created',
            'layout_type' => self::LAYOUT_TYPE,
            'layout_option' => $layoutOption,
            'name' => $name,
            'file' => $targetPath,
            'clone_from' => $cloneFrom,
            'scoped_workspace_warmed' => $workspaceWarmed,
            'editor_hint' => [
                'page_type' => self::LAYOUT_TYPE,
                'layout_option' => $layoutOption,
                'lock_layout' => 1,
                'lock_source' => 'product',
                'product_layout_mode' => 1,
            ],
        ];
    }

    public function optionExists(string $layoutOption, string $area = 'frontend'): bool
    {
        $layoutOption = $this->virtualLayout->normalizeLayoutOption($layoutOption);
        if ($layoutOption === '') {
            return false;
        }
        foreach ($this->listProductOptions($area) as $row) {
            if ($row['value'] === $layoutOption) {
                return true;
            }
        }

        return false;
    }

    private function resolveLayoutFilePath(string $area, string $option): ?string
    {
        $theme = $this->resolveTheme($area, null);
        $byType = $this->catalog->getLayouts($area, $theme);
        $rows = is_array($byType[self::LAYOUT_TYPE] ?? null) ? $byType[self::LAYOUT_TYPE] : [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            if ($this->virtualLayout->normalizeLayoutOption((string)($row['value'] ?? '')) !== $option) {
                continue;
            }
            $path = (string)($row['path'] ?? '');
            if ($path !== '' && is_file($path)) {
                return $path;
            }
        }

        $fallback = $this->moduleLayoutsDirectory($area) . DIRECTORY_SEPARATOR . $option . '.phtml';

        return is_file($fallback) ? $fallback : null;
    }

    private function moduleLayoutsDirectory(string $area): string
    {
        $area = $area === 'backend' ? 'backend' : 'frontend';

        return BP . '/app/code/Weline/Theme/view/theme/' . $area . '/layouts/' . self::LAYOUT_TYPE;
    }

    private function rewriteClonedShell(string $source, string $option, string $name, string $cloneFrom): string
    {
        $source = preg_replace(
            '/data-weshop-layout-option="' . preg_quote($cloneFrom, '/') . '"/',
            'data-weshop-layout-option="' . $option . '"',
            $source
        ) ?? $source;
        $source = preg_replace(
            '/weshop-product-layout--' . preg_quote($cloneFrom, '/') . '/',
            'weshop-product-layout--' . $option,
            $source
        ) ?? $source;
        if (!str_contains($source, '@meta.name')) {
            $header = "<?php\n"
                . "/**\n"
                . " * @meta.name {default=\"{$name}\",name=\"{$name}\",description=\"产品布局 {$option}\"}\n"
                . " * @meta.description {default=\"克隆自 {$cloneFrom} 的产品布局选项\",name=\"描述\",description=\"产品布局 {$option}\"}\n"
                . " */\n";
            if (str_starts_with(ltrim($source), '<?php')) {
                $source = preg_replace('/^<\?php\s*/', $header, ltrim($source), 1) ?? ($header . $source);
            } else {
                $source = $header . $source;
            }
        }

        return $source;
    }

    private function humanize(string $option): string
    {
        $option = str_replace(['-', '_'], ' ', $option);

        return ucwords($option);
    }

    /**
     * Best-effort scoped workspace warm-up for the new layout_option (no virtual content table).
     */
    private function warmScopedWorkspace(string $layoutOption, string $area): bool
    {
        try {
            $theme = $this->resolveTheme($area, null);
            $themeId = $theme ? (int)$theme->getId() : 0;
            if ($themeId <= 0) {
                return false;
            }
            /** @var ThemeLayoutService $layouts */
            $layouts = ObjectManager::getInstance(ThemeLayoutService::class);

            return $layouts->initDraftFromPublished($themeId, self::LAYOUT_TYPE, [
                'layout_option' => $layoutOption,
            ]);
        } catch (\Throwable) {
            return false;
        }
    }

    private function resolveTheme(string $area, ?int $themeId): ?WelineTheme
    {
        try {
            /** @var WelineTheme $theme */
            $theme = ObjectManager::getInstance(WelineTheme::class);
            $theme = clone $theme;
            if ($themeId !== null && $themeId > 0) {
                $theme->clearData()->clearQuery()->load($themeId);

                return $theme->getId() ? $theme : null;
            }
            $theme->clearData()->clearQuery()->getActiveTheme($area);

            return $theme->getId() ? $theme : null;
        } catch (\Throwable) {
            return null;
        }
    }
}

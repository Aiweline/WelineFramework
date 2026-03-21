<?php
declare(strict_types=1);

namespace Weline\Theme\Extends\Module\Weline_Framework\Query;

use Weline\Framework\Service\Query\Provider\QueryProviderInterface;
use Weline\Theme\Model\WelineTheme;

/**
 * 主题查询器
 *
 * 提供 getActiveTheme、getConfigValue 等能力，供其他模块通过 w_query('theme', ...) 调用。
 */
class ThemeQueryProvider implements QueryProviderInterface
{
    public function __construct(
        private readonly WelineTheme $welineTheme
    ) {
    }

    public function getProviderName(): string
    {
        return 'theme';
    }

    public function execute(string $operation, array $params = []): mixed
    {
        return match ($operation) {
            'getActiveTheme' => $this->getActiveTheme($params),
            'getConfigValue' => $this->getConfigValue($params),
            'getTemplatePath' => $this->getTemplatePath($params),
            'scanThemeLayoutsByType' => $this->scanThemeLayoutsByType($params),
            default => throw new \InvalidArgumentException(
                (string)__('Theme 查询器不支持的操作：%{1}', $operation)
            ),
        };
    }

    private function getActiveTheme(array $params): ?array
    {
        $area = $this->normalizeArea($params['area'] ?? null);
        $theme = clone $this->welineTheme;
        $theme->getActiveTheme($area);

        if (!$theme->getId()) {
            return null;
        }

        return [
            'id' => $theme->getId(),
            'name' => $theme->getData(WelineTheme::schema_fields_NAME),
            'module_name' => $theme->getData(WelineTheme::schema_fields_MODULE_NAME),
            'path' => $theme->getData(WelineTheme::schema_fields_PATH),
            'parent_id' => $theme->getData(WelineTheme::schema_fields_PARENT_ID),
            'is_active' => $theme->getData(WelineTheme::schema_fields_IS_ACTIVE),
            'config' => $theme->getData(WelineTheme::schema_fields_CONFIG),
            'preview_image' => $theme->getPreviewImage(),
            'frontend_preview_image' => $theme->getFrontendPreviewImage(),
            'backend_preview_image' => $theme->getBackendPreviewImage(),
        ];
    }

    private function getConfigValue(array $params): ?string
    {
        $layout = (string)($params['layout'] ?? '');
        $area = (string)($params['area'] ?? '');
        $locale = (string)($params['locale'] ?? '');
        $field = (string)($params['field'] ?? 'value');

        if ($layout === '') {
            return null;
        }

        return \Weline\Theme\Helper\ThemeConfigHelper::getConfigValue(
            $layout,
            $area !== '' ? $area : null,
            $locale !== '' ? $locale : null,
            $field
        );
    }

    private function getTemplatePath(array $params): string
    {
        $layout = (string)($params['layout'] ?? '');
        $area = (string)($params['area'] ?? '');
        $locale = (string)($params['locale'] ?? '');
        $defaultValue = (string)($params['default_value'] ?? 'default');

        if ($layout === '') {
            return '';
        }

        return \Weline\Theme\Helper\ThemeConfigHelper::getTemplatePath(
            $layout,
            $area !== '' ? $area : null,
            $locale !== '' ? $locale : null,
            $defaultValue
        );
    }

    /**
     * 扫描当前激活主题中指定类型的布局文件（含主题继承链）
     */
    private function scanThemeLayoutsByType(array $params): array
    {
        $layoutType = (string)($params['layout_type'] ?? '');
        $area = $this->normalizeArea($params['area'] ?? 'frontend') ?? 'frontend';
        if ($layoutType === '') {
            return [];
        }
        $theme = clone $this->welineTheme;
        $theme->getActiveTheme($area);
        if (!$theme->getId()) {
            return [];
        }
        return $this->doScanThemeLayouts($layoutType, $area, $theme);
    }

    private function doScanThemeLayouts(string $layoutType, string $area, WelineTheme $theme): array
    {
        $layouts = [];
        $themePath = $theme->getPath();
        if ($themePath === '' || !is_dir($themePath)) {
            $parent = $theme->getParentTheme();
            return $parent ? $this->doScanThemeLayouts($layoutType, $area, $parent) : [];
        }
        $ds = \DIRECTORY_SEPARATOR;
        $layoutsDir = rtrim($themePath, $ds) . $ds . 'view' . $ds . 'theme' . $ds . $area . $ds . 'layouts' . $ds . $layoutType;
        if (!is_dir($layoutsDir)) {
            $parent = $theme->getParentTheme();
            return $parent ? $this->doScanThemeLayouts($layoutType, $area, $parent) : [];
        }
        $themeCode = $theme->getModuleName() ?: 'Weline_Theme';
        $files = glob($layoutsDir . $ds . '*.phtml') ?: [];
        foreach ($files as $file) {
            $fileName = basename($file, '.phtml');
            $layoutPath = $themeCode . '::theme/' . $area . '/layouts/' . $layoutType . '/' . $fileName;
            $meta = $this->parseLayoutMeta($file);
            $layouts[$fileName] = [
                'name' => $meta['name'] ?? ucfirst($fileName),
                'description' => $meta['description'] ?? '',
                'template' => $layoutPath,
                'preview_image' => $meta['preview_image'] ?? '',
                'config' => $meta['config'] ?? [],
            ];
        }
        return $layouts;
    }

    private function parseLayoutMeta(string $filePath): array
    {
        $meta = [];
        if (!is_file($filePath)) {
            return $meta;
        }
        $content = file_get_contents($filePath);
        if (preg_match('/@meta\.name\s*\{[^}]*name\s*=\s*"([^"]+)"/', $content, $m)) {
            $meta['name'] = $m[1];
        }
        if (preg_match('/@meta\.description\s*\{[^}]*description\s*=\s*"([^"]+)"/', $content, $m)) {
            $meta['description'] = $m[1];
        }
        if (preg_match('/@preview_image\s*\{[^}]*default\s*=\s*"([^"]+)"/', $content, $m)) {
            $meta['preview_image'] = $m[1];
        }
        return $meta;
    }

    public function getDescriptor(): array
    {
        return [
            'provider' => 'theme',
            'name' => __('主题查询'),
            'description' => __('提供当前主题、配置值、模板路径等查询能力'),
            'module' => 'Weline_Theme',
            'operations' => [
                [
                    'name' => 'getActiveTheme',
                    'description' => __('获取当前激活的主题信息'),
                    'params' => [
                        ['name' => 'area', 'type' => 'string', 'required' => false, 'description' => __('可选：frontend 或 backend')],
                    ],
                ],
                [
                    'name' => 'getConfigValue',
                    'description' => __('获取主题配置值'),
                    'params' => [
                        ['name' => 'layout', 'type' => 'string', 'required' => true],
                        ['name' => 'area', 'type' => 'string', 'required' => false],
                        ['name' => 'locale', 'type' => 'string', 'required' => false],
                        ['name' => 'field', 'type' => 'string', 'required' => false],
                    ],
                ],
                [
                    'name' => 'getTemplatePath',
                    'description' => __('获取主题配置的模板路径'),
                    'params' => [
                        ['name' => 'layout', 'type' => 'string', 'required' => true],
                        ['name' => 'area', 'type' => 'string', 'required' => false],
                        ['name' => 'locale', 'type' => 'string', 'required' => false],
                        ['name' => 'default_value', 'type' => 'string', 'required' => false],
                    ],
                ],
                [
                    'name' => 'scanThemeLayoutsByType',
                    'description' => __('扫描当前主题中指定类型的布局选项'),
                    'params' => [
                        ['name' => 'layout_type', 'type' => 'string', 'required' => true],
                        ['name' => 'area', 'type' => 'string', 'required' => false, 'description' => __('默认 frontend')],
                    ],
                ],
            ],
        ];
    }

    private function normalizeArea(mixed $area): ?string
    {
        $area = strtolower(trim((string)$area));

        return match ($area) {
            'frontend' => 'frontend',
            'backend' => 'backend',
            default => null,
        };
    }
}

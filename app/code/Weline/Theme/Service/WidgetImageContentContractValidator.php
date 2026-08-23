<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Theme\Api\Layout\LayoutContentValidatorInterface;
use Weline\Widget\Service\WidgetConfigService;

/** Enforces typed FileAsset references for all newly published widget image fields. */
final class WidgetImageContentContractValidator implements LayoutContentValidatorInterface
{
    private const IMAGE_TYPES = ['image', 'media_image', 'file_image'];
    private const RESERVED_COMPANION_SUFFIXES = [
        '_file_html',
        '_file_usage',
        '_file_alt',
        '_file_asset_id',
    ];
    private const MAX_NODES = 10000;
    private const MAX_DEPTH = 64;

    public function __construct(
        private readonly WidgetConfigService $widgetConfig,
        private readonly ThemePlaceableRegistry $placeables,
        private readonly LayoutValueHydrationRegistry $valueHydrators,
    ) {
    }

    public function validate(array $layoutData, array $context): void
    {
        $nodes = 0;
        foreach ($layoutData as $areaData) {
            if (!is_array($areaData)) {
                continue;
            }
            // ThemeLayoutService::saveLayout receives area => widget-list,
            // while published snapshots expose area => ['widgets' => list].
            // Validate both representations at the persistence boundary.
            $widgets = array_is_list($areaData)
                ? $areaData
                : (is_array($areaData['widgets'] ?? null) ? $areaData['widgets'] : []);
            foreach ($widgets as $widget) {
                if (!is_array($widget)) {
                    continue;
                }
                $config = is_array($widget['config'] ?? null) ? $widget['config'] : [];
                $this->assertNoDynamicImageMarkup($config, 0, $nodes);
                $definitions = $this->definitions($widget);
                foreach ($definitions as $key => $definition) {
                    if (!is_array($definition) || !array_key_exists($key, $config)) {
                        continue;
                    }
                    $this->validateValue($config[$key], $definition, '$.config.' . $key, 0, $nodes);
                }
            }
        }
    }

    /** @param array<string,mixed> $widget @return array<string,mixed> */
    private function definitions(array $widget): array
    {
        $module = trim((string)($widget['widget_module'] ?? ''));
        $code = trim((string)($widget['widget_code'] ?? ''));
        $type = trim((string)($widget['widget_type'] ?? ''));
        if ($module === '' || $code === '') {
            throw new \RuntimeException((string)__('主题布局包含缺少身份的部件，禁止保存或发布。'));
        }
        $definitions = $this->widgetConfig->getParamDefinitions($module, $code, 'frontend');
        if ($definitions !== []) {
            return $definitions;
        }
        $definition = $this->placeables->find($module, $type, $code, null, 'frontend');
        if ($definition === null) {
            throw new \RuntimeException((string)__('主题部件 %{1} 未注册，禁止保存或发布。', [
                $module . '::' . $type . '::' . $code,
            ]));
        }
        return $definition->params ?: $definition->configSchema;
    }

    /** @param array<string,mixed> $definition */
    private function validateValue(
        mixed $value,
        array $definition,
        string $path,
        int $depth,
        int &$nodes,
    ): void
    {
        $this->guardTraversal($depth, $nodes);
        $type = strtolower(trim((string)(
            $definition['ui_type'] ?? $definition['input'] ?? $definition['type'] ?? 'string'
        )));
        if (in_array($type, self::IMAGE_TYPES, true)) {
            if ($value === null || $value === '') {
                return;
            }
            $node = $this->normalizeFileImageNode($value);
            if ($node === null) {
                throw new \RuntimeException((string)__('图片字段 %{1} 仍是旧 URL；请从媒体库重新选择资源后再发布。', [$path]));
            }
            if (!$this->valueHydrators->supports($node)) {
                throw new \RuntimeException((string)__('图片字段 %{1} 缺少可用的 file-image 运行时适配器。', [$path]));
            }
            return;
        }

        if (($type === 'array' || $type === 'list') && is_array($definition['item_schema'] ?? null)) {
            if (is_string($value)) {
                $decoded = json_decode($value, true);
                $value = is_array($decoded) ? $decoded : [];
            }
            if (!is_array($value)) {
                return;
            }
            foreach ($value as $index => $item) {
                if (!is_array($item)) {
                    continue;
                }
                foreach ($definition['item_schema'] as $field => $fieldDefinition) {
                    if (is_array($fieldDefinition) && array_key_exists($field, $item)) {
                        $this->validateValue(
                            $item[$field],
                            $fieldDefinition,
                            $path . '[' . $index . '].' . $field,
                            $depth + 1,
                            $nodes,
                        );
                    }
                }
            }
        }
    }

    /** @return array{type:string,usage:array<string,mixed>}|null */
    private function normalizeFileImageNode(mixed $value): ?array
    {
        // Typed layout values must remain structural arrays all the way to the
        // shared validator and runtime hydrator. Accepting an embedded JSON
        // string here would let publication skip FileAsset validation and the
        // derived reference index because those walkers intentionally recurse
        // through typed arrays only.
        if (!is_array($value)
            || ($value['type'] ?? null) !== 'file-image'
            || !is_array($value['usage'] ?? null)
        ) {
            return null;
        }
        return ['type' => 'file-image', 'usage' => $value['usage']];
    }

    /** @param array<string|int,mixed> $value */
    private function assertNoDynamicImageMarkup(array $value, int $depth, int &$nodes): void
    {
        $this->guardTraversal($depth, $nodes);
        if (($value['type'] ?? null) === 'file-image') {
            $node = $this->normalizeFileImageNode($value);
            if ($node === null) {
                throw new \RuntimeException((string)__('file-image 节点缺少类型化 usage。'));
            }
            if (!$this->valueHydrators->supports($node)) {
                throw new \RuntimeException((string)__('file-image 节点缺少可用的运行时适配器。'));
            }
        }
        foreach ($value as $key => $child) {
            if (is_string($key) && $this->isReservedCompanionKey($key)) {
                throw new \RuntimeException((string)__('布局内容不得持久化运行时图片伴生字段：%{1}', [$key]));
            }
            if (is_array($child)) {
                $this->assertNoDynamicImageMarkup($child, $depth + 1, $nodes);
            } elseif (is_string($child) && preg_match('/<img\b[^>]*\bsrc\s*=/i', $child) === 1) {
                throw new \RuntimeException((string)__('动态 <img src> 内容禁止保存，请改用 file-image 类型节点。'));
            }
        }
    }

    private function isReservedCompanionKey(string $key): bool
    {
        foreach (self::RESERVED_COMPANION_SUFFIXES as $suffix) {
            if (str_ends_with($key, $suffix)) {
                return true;
            }
        }
        return false;
    }

    private function guardTraversal(int $depth, int &$nodes): void
    {
        if (++$nodes > self::MAX_NODES || $depth > self::MAX_DEPTH) {
            throw new \RuntimeException((string)__('主题布局图片内容超过校验上限。'));
        }
    }
}

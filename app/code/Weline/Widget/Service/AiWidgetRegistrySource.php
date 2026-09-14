<?php

declare(strict_types=1);

namespace Weline\Widget\Service;

use Weline\Widget\Model\AiWidget;

class AiWidgetRegistrySource
{
    public function __construct(
        private readonly AiWidget $aiWidget,
        private readonly ?\Weline\Framework\Cache\Service\StorefrontScopeHotCache $hotCache = null,
    ) {
    }

    public function getRegistryEntries(): array
    {
        try {
            $cache = $this->hotCache ?? \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Framework\Cache\Service\StorefrontScopeHotCache::class);
            return $cache->rememberPolicy(
                new \Weline\Framework\Cache\CachePolicy(
                    resource: 'widget.ai_registry',
                    pool: 'weline_widget_ai_registry',
                    scope: 'global',
                    dependencies: [AiWidgetRegistryMutation::NAMESPACE],
                    freshTtlSeconds: 300,
                    staleTtlSeconds: 1800,
                ),
                'active-definitions.v1',
                fn(): array => $this->loadRegistryEntries(),
            );
        } catch (\Throwable $e) {
            // 失败保持原来的空回退，但不能将数据库失败发布成权威空注册表。
            w_log_error('读取 AI Widget 注册数据失败: ' . $e->getMessage(), [], 'AiWidgetRegistrySource');
            return [];
        }
    }

    private function loadRegistryEntries(): array
    {
        $rows = (clone $this->aiWidget)
            ->clearData()
            ->clearQuery()
            ->where(AiWidget::schema_fields_IS_ACTIVE, 1)
            ->select()
            ->fetchArray();

        $registry = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $widget = $this->rowToWidget($row);
            $type = (string)($widget['type'] ?? 'content');
            $code = (string)($widget['code'] ?? '');
            if ($code === '') {
                continue;
            }
            $registry[$type][$code] = $widget;
        }

        return $registry;
    }

    private function rowToWidget(array $row): array
    {
        $code = (string)($row[AiWidget::schema_fields_WIDGET_CODE] ?? '');
        $type = (string)($row[AiWidget::schema_fields_TYPE] ?? 'content');
        $params = $this->decodeJson((string)($row[AiWidget::schema_fields_PARAMS_JSON] ?? ''), []);
        $defaultConfig = $this->decodeJson((string)($row[AiWidget::schema_fields_DEFAULT_CONFIG_JSON] ?? ''), []);
        $meta = $this->decodeJson((string)($row[AiWidget::schema_fields_META_JSON] ?? ''), []);
        $position = $this->decodeJson((string)($row[AiWidget::schema_fields_POSITION_JSON] ?? ''), []);
        $pageLayouts = $this->decodeJson((string)($row[AiWidget::schema_fields_PAGE_LAYOUTS_JSON] ?? ''), []);
        $supports = $this->decodeJson((string)($row[AiWidget::schema_fields_SUPPORTS_JSON] ?? ''), []);
        $slots = $this->decodeJson((string)($row[AiWidget::schema_fields_SLOTS_JSON] ?? ''), []);

        if (!isset($meta['is_ai_generated'])) {
            $meta['is_ai_generated'] = true;
        }
        $meta['source'] = $meta['source'] ?? 'ai';
        $meta['ai_widget_id'] = (int)($row[AiWidget::schema_fields_ID] ?? 0);

        return [
            'module' => 'Weline_Widget',
            'type' => $type,
            'code' => $code,
            'name' => (string)($row[AiWidget::schema_fields_NAME] ?? $code),
            'description' => (string)($row[AiWidget::schema_fields_DESCRIPTION] ?? ''),
            'template' => '',
            'template_content' => (string)($row[AiWidget::schema_fields_TEMPLATE_CONTENT] ?? ''),
            'params' => $params,
            'config' => [
                'position' => $position,
                'page_layouts' => $pageLayouts,
                'supports' => $supports,
                'slot' => (string)($row[AiWidget::schema_fields_SLOT] ?? ''),
                'slots' => $slots,
                'exclusive' => !empty($row[AiWidget::schema_fields_EXCLUSIVE]),
                'compatible' => !isset($row[AiWidget::schema_fields_COMPATIBLE]) || (bool)$row[AiWidget::schema_fields_COMPATIBLE],
            ],
            'default_config' => $defaultConfig,
            'position' => $position,
            'page_layouts' => $pageLayouts,
            'supports' => $supports,
            'slot' => (string)($row[AiWidget::schema_fields_SLOT] ?? ''),
            'slots' => $slots,
            'exclusive' => !empty($row[AiWidget::schema_fields_EXCLUSIVE]),
            'compatible' => !isset($row[AiWidget::schema_fields_COMPATIBLE]) || (bool)$row[AiWidget::schema_fields_COMPATIBLE],
            'is_container' => !empty($row[AiWidget::schema_fields_IS_CONTAINER]),
            'is_ai_generated' => true,
            'ai_widget_id' => (int)($row[AiWidget::schema_fields_ID] ?? 0),
            'meta' => $meta,
        ];
    }

    private function decodeJson(string $raw, array $default): array
    {
        if (trim($raw) === '') {
            return $default;
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : $default;
    }
}

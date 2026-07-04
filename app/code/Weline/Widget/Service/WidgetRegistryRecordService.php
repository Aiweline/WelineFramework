<?php

declare(strict_types=1);

namespace Weline\Widget\Service;

use Weline\Widget\Model\WidgetRegistryEntry;

class WidgetRegistryRecordService
{
    public function __construct(
        private readonly WidgetRegistryEntry $registryEntry,
    ) {
    }

    /**
     * @param array<string,array<string,array<string,mixed>>> $registry
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    public function sync(array $registry, array $context = []): array
    {
        $report = [
            'db_available' => true,
            'created_widgets' => [],
            'updated_widgets' => [],
            'created_default_injection_widgets' => [],
            'created_count' => 0,
            'updated_count' => 0,
            'created_default_injection_count' => 0,
            'error' => null,
        ];

        try {
            foreach ($this->flattenRegistry($registry) as $widget) {
                $entry = $this->syncWidget($widget, $context);
                if ($entry === null) {
                    continue;
                }

                if (!empty($entry['created'])) {
                    $report['created_widgets'][] = $entry['widget'];
                    $report['created_count']++;
                    if (!empty($entry['has_default_injections'])) {
                        $report['created_default_injection_widgets'][] = $entry['widget'];
                        $report['created_default_injection_count']++;
                    }
                    continue;
                }

                if (!empty($entry['updated'])) {
                    $report['updated_widgets'][] = $entry['widget'];
                    $report['updated_count']++;
                }
            }
        } catch (\Throwable $e) {
            $report['db_available'] = false;
            $report['error'] = $e->getMessage();
            w_log_error('同步普通 Widget 注册账本失败: ' . $e->getMessage(), [], 'WidgetRegistryEntry');
        }

        return $report;
    }

    /**
     * @param array<string,array<string,array<string,mixed>>> $registry
     * @return \Generator<int,array<string,mixed>>
     */
    private function flattenRegistry(array $registry): \Generator
    {
        foreach ($registry as $type => $widgets) {
            if (!is_array($widgets)) {
                continue;
            }

            foreach ($widgets as $name => $widget) {
                if (!is_array($widget) || !empty($widget['is_ai_generated'])) {
                    continue;
                }

                $module = trim((string)($widget['module'] ?? ''));
                $widgetType = trim((string)($widget['type'] ?? $type));
                $code = trim((string)($widget['code'] ?? $name));
                if ($module === '' || $widgetType === '' || $code === '') {
                    continue;
                }

                $area = trim((string)($widget['area'] ?? 'frontend'));
                $widget['area'] = $area !== '' ? $area : 'frontend';
                $widget['module'] = $module;
                $widget['type'] = $widgetType;
                $widget['code'] = $code;

                yield $widget;
            }
        }
    }

    /**
     * @param array<string,mixed> $widget
     * @param array<string,mixed> $context
     * @return array{created:bool,updated:bool,has_default_injections:bool,widget:array<string,mixed>}|null
     */
    private function syncWidget(array $widget, array $context): ?array
    {
        $area = (string)$widget['area'];
        $module = (string)$widget['module'];
        $type = (string)$widget['type'];
        $code = (string)$widget['code'];
        $defaultInjections = $this->normalizeDefaultInjections(
            $widget['default_injections'] ?? ($widget['config']['default_injections'] ?? [])
        );
        $hash = hash('sha256', $this->encodeJson($widget));
        $now = date('Y-m-d H:i:s');

        $existingRow = $this->findExistingRow($area, $module, $type, $code);
        $registryId = (int)($existingRow[WidgetRegistryEntry::schema_fields_ID] ?? 0);
        $previousHash = (string)($existingRow[WidgetRegistryEntry::schema_fields_CONFIG_HASH] ?? '');
        $created = $registryId <= 0;
        $updated = !$created && $previousHash !== $hash;

        $model = clone $this->registryEntry;
        if (!$created) {
            $model->clearQuery()->clearData()->load($registryId);
        } else {
            $model->clearQuery()->clearData();
        }

        $model
            ->setData(WidgetRegistryEntry::schema_fields_WIDGET_AREA, $area)
            ->setData(WidgetRegistryEntry::schema_fields_WIDGET_MODULE, $module)
            ->setData(WidgetRegistryEntry::schema_fields_WIDGET_TYPE, $type)
            ->setData(WidgetRegistryEntry::schema_fields_WIDGET_CODE, $code)
            ->setData(WidgetRegistryEntry::schema_fields_WIDGET_NAME, $this->truncate((string)($widget['name'] ?? $code), 255))
            ->setData(WidgetRegistryEntry::schema_fields_DESCRIPTION, $this->truncate((string)($widget['description'] ?? ''), 500))
            ->setData(WidgetRegistryEntry::schema_fields_TEMPLATE, $this->truncate((string)($widget['template'] ?? ''), 500))
            ->setData(WidgetRegistryEntry::schema_fields_WIDGET_FILE, $this->truncate((string)($widget['widget_file'] ?? ''), 500))
            ->setData(WidgetRegistryEntry::schema_fields_VERSION, $this->truncate((string)($widget['version'] ?? ''), 50))
            ->setData(WidgetRegistryEntry::schema_fields_CONFIG_HASH, $hash)
            ->setData(WidgetRegistryEntry::schema_fields_HAS_DEFAULT_INJECTIONS, $defaultInjections !== [] ? 1 : 0)
            ->setData(WidgetRegistryEntry::schema_fields_DEFAULT_INJECTIONS_JSON, $defaultInjections !== [] ? $this->encodeJson($defaultInjections) : null)
            ->setData(WidgetRegistryEntry::schema_fields_REGISTRY_JSON, $this->encodeJson($widget))
            ->setData(WidgetRegistryEntry::schema_fields_COLLECTION_SOURCE, $this->truncate((string)($context['source'] ?? ''), 64))
            ->setData(WidgetRegistryEntry::schema_fields_IS_ACTIVE, empty($widget['disabled']) ? 1 : 0)
            ->setData(WidgetRegistryEntry::schema_fields_LAST_COLLECTED_AT, $now)
            ->save();

        $registryId = $created ? $model->getRegistryId() : $registryId;

        return [
            'created' => $created,
            'updated' => $updated,
            'has_default_injections' => $defaultInjections !== [],
            'widget' => [
                'registry_id' => $registryId,
                'area' => $area,
                'widget_area' => $area,
                'module' => $module,
                'widget_module' => $module,
                'type' => $type,
                'widget_type' => $type,
                'code' => $code,
                'widget_code' => $code,
                'name' => (string)($widget['name'] ?? $code),
                'widget_file' => (string)($widget['widget_file'] ?? ''),
                'default_injections' => $defaultInjections,
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function findExistingRow(string $area, string $module, string $type, string $code): array
    {
        $row = (clone $this->registryEntry)->clearQuery()->clearData()
            ->where(WidgetRegistryEntry::schema_fields_WIDGET_AREA, $area)
            ->where(WidgetRegistryEntry::schema_fields_WIDGET_MODULE, $module)
            ->where(WidgetRegistryEntry::schema_fields_WIDGET_TYPE, $type)
            ->where(WidgetRegistryEntry::schema_fields_WIDGET_CODE, $code)
            ->find()
            ->fetchArray();

        if (is_array($row) && isset($row[0]) && is_array($row[0])) {
            return $row[0];
        }

        return is_array($row) ? $row : [];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function normalizeDefaultInjections(mixed $value): array
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : [];
        }
        if (!is_array($value) || $value === []) {
            return [];
        }

        $isList = array_keys($value) === range(0, count($value) - 1);
        $items = $isList ? $value : [$value];
        $result = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $result[] = $item;
            }
        }

        return $result;
    }

    private function encodeJson(mixed $value): string
    {
        $json = json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );

        return is_string($json) ? $json : '[]';
    }

    private function truncate(string $value, int $length): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return mb_substr($value, 0, $length);
    }
}

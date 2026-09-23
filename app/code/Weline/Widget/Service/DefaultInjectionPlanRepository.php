<?php

declare(strict_types=1);

namespace Weline\Widget\Service;

use Weline\Widget\Model\WidgetRegistryEntry;

/**
 * Solidify-time reader for default_injections plans from widget_registry_entry.
 *
 * Authority: Theme/doc/布局固化与默认注入.md — plan SoT is the registry ledger;
 * ingest via upgrade / cron / widget:refresh; never scan files here.
 */
final class DefaultInjectionPlanRepository
{
    /** @var list<array<string, mixed>>|null */
    private ?array $memo = null;

    private ?string $memoArea = null;

    public function __construct(
        private readonly WidgetRegistryEntry $registryEntry,
    ) {
    }

    /**
     * Declarations shaped for bake/overlay/collect (module/type/code/default_injections/slots…).
     *
     * @return list<array<string, mixed>>
     */
    public function listDeclarations(?string $area = 'frontend'): array
    {
        $areaKey = $area === null ? '' : trim($area);
        if ($this->memo !== null && $this->memoArea === $areaKey) {
            return $this->memo;
        }

        try {
            $query = (clone $this->registryEntry)->clearQuery()->clearData()
                ->where(WidgetRegistryEntry::schema_fields_IS_ACTIVE, 1)
                ->where(WidgetRegistryEntry::schema_fields_HAS_DEFAULT_INJECTIONS, 1);
            if ($areaKey !== '') {
                $query->where(WidgetRegistryEntry::schema_fields_WIDGET_AREA, $areaKey);
            }
            $rows = $query->select()->fetchArray();
        } catch (\Throwable $e) {
            w_log_error(
                '读取默认注入计划账本失败: ' . $e->getMessage(),
                [],
                'DefaultInjectionPlanRepository'
            );
            $this->memo = [];
            $this->memoArea = $areaKey;

            return [];
        }

        if (!is_array($rows) || $rows === []) {
            $this->memo = [];
            $this->memoArea = $areaKey;

            return [];
        }
        // Some adapters return a single associative row instead of a list.
        if (!array_is_list($rows) && (
            isset($rows[WidgetRegistryEntry::schema_fields_WIDGET_MODULE])
            || isset($rows[WidgetRegistryEntry::schema_fields_ID])
        )) {
            $rows = [$rows];
        }

        $declarations = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $declaration = $this->rowToDeclaration($row);
            if ($declaration === null) {
                continue;
            }
            $declarations[] = $declaration;
        }

        $this->memo = $declarations;
        $this->memoArea = $areaKey;

        return $declarations;
    }

    public function clearMemo(): void
    {
        $this->memo = null;
        $this->memoArea = null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function rowToDeclaration(array $row): ?array
    {
        $module = trim((string)($row[WidgetRegistryEntry::schema_fields_WIDGET_MODULE] ?? ''));
        $type = trim((string)($row[WidgetRegistryEntry::schema_fields_WIDGET_TYPE] ?? ''));
        $code = trim((string)($row[WidgetRegistryEntry::schema_fields_WIDGET_CODE] ?? ''));
        if ($module === '' || $type === '' || $code === '') {
            return null;
        }

        $registry = $this->decodeJsonObject($row[WidgetRegistryEntry::schema_fields_REGISTRY_JSON] ?? null);
        $defaultInjections = $this->normalizeDefaultInjections(
            $row[WidgetRegistryEntry::schema_fields_DEFAULT_INJECTIONS_JSON] ?? null
        );
        if ($defaultInjections === [] && $registry !== []) {
            $defaultInjections = $this->normalizeDefaultInjections(
                $registry['default_injections'] ?? ($registry['config']['default_injections'] ?? [])
            );
        }
        if ($defaultInjections === []) {
            return null;
        }

        $slots = [];
        if (isset($registry['slots']) && is_array($registry['slots'])) {
            $slots = $registry['slots'];
        }

        $area = trim((string)($row[WidgetRegistryEntry::schema_fields_WIDGET_AREA] ?? 'frontend'));
        if ($area === '') {
            $area = 'frontend';
        }

        return [
            'module' => $module,
            'type' => $type,
            'code' => $code,
            'area' => $area,
            'name' => (string)($row[WidgetRegistryEntry::schema_fields_WIDGET_NAME] ?? ($registry['name'] ?? $code)),
            'description' => (string)($row[WidgetRegistryEntry::schema_fields_DESCRIPTION] ?? ($registry['description'] ?? '')),
            'default_injections' => $defaultInjections,
            'slots' => $slots,
            'slot' => !empty($registry['slot']) ? (string)$registry['slot'] : null,
            'exclusive' => (bool)($registry['exclusive'] ?? false),
            'compatible' => (bool)($registry['compatible'] ?? false),
            'is_container' => (bool)($registry['is_container'] ?? false),
            'supports' => is_array($registry['supports'] ?? null) ? $registry['supports'] : [],
            'position' => is_array($registry['position'] ?? null) ? $registry['position'] : [],
            'page_layouts' => is_array($registry['page_layouts'] ?? null) ? $registry['page_layouts'] : ['*'],
            'params' => is_array($registry['params'] ?? null) ? $registry['params'] : [],
            'template' => (string)($row[WidgetRegistryEntry::schema_fields_TEMPLATE] ?? ($registry['template'] ?? '')),
            'registry' => $registry,
        ];
    }

    /**
     * @return list<array<string, mixed>>
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
            if (is_array($item) && $item !== []) {
                $result[] = $item;
            }
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonObject(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }
}

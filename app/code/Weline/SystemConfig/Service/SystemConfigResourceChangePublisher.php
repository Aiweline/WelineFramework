<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Service;

use Weline\Framework\Cache\Namespace\NamespacePath;
use Weline\Framework\Event\ResourceChange\ResourceChange;
use Weline\Framework\Event\ResourceChange\ResourceChangeFactory;
use Weline\Framework\Event\ResourceChange\ResourceRevisionService;
use Weline\SystemConfig\Model\SystemConfig;

final class SystemConfigResourceChangePublisher
{
    public function __construct(
        private readonly ResourceRevisionService $revisions,
        private readonly ResourceChangeFactory $changes,
        private readonly NamespacePath $namespacePath,
    ) {
    }

    /** @param list<array<string,mixed>> $changes */
    public function publish(
        string $module,
        string $area,
        string $scope,
        string $locale,
        array $changes,
        string $entry,
    ): ?ResourceChange {
        $records = array_values(array_filter(array_map(
            fn(array $change): ?array => $this->sanitizeChange($change),
            $changes,
        )));
        if ($records === []) {
            return null;
        }

        $identity = implode('|', [$module, $area, $scope, $locale]);
        $resourceId = strlen($identity) <= 191 ? $identity : 'sha256:' . hash('sha256', $identity);
        $changedFields = array_values(array_unique(array_map(
            static fn(array $record): string => (string)$record['key'],
            $records,
        )));
        sort($changedFields, SORT_STRING);
        $before = $this->snapshot($module, $area, $scope, $locale, $records, 'before');
        $after = $this->snapshot($module, $area, $scope, $locale, $records, 'after');
        $revision = $this->revisions->next('system_config', $resourceId);
        $change = $this->changes->create(
            resourceType: 'system_config',
            resourceId: $resourceId,
            action: 'upsert',
            revision: $revision,
            websiteId: 0,
            websiteCode: 'default',
            before: $before,
            after: $after,
            changedFields: $changedFields,
            impact: [
                'namespaces' => $this->impactNamespaces($module, $scope, $identity),
            ],
            origin: ['entry' => $entry],
        );
        w_changed($change);
        return $change;
    }

    /** @return list<string> */
    private function impactNamespaces(string $module, string $scope, string $identity): array
    {
        $namespaces = [
            $this->namespacePath->global('system-config', [hash('sha256', $identity)]),
            $this->namespacePath->global('storefront', ['config']),
        ];
        $websiteCode = trim((string)(explode('.', strtolower($scope), 2)[0] ?? ''));
        if ($websiteCode !== '' && $websiteCode !== 'default') {
            $namespaces[] = $this->namespacePath->website($websiteCode, ['config']);
        }

        $dimension = match ($module) {
            'Weline_Theme' => 'theme',
            'Weline_Currency' => 'price',
            default => null,
        };
        if ($dimension !== null) {
            $namespaces[] = $this->namespacePath->global('storefront', [$dimension]);
            if ($websiteCode !== '' && $websiteCode !== 'default') {
                $namespaces[] = $this->namespacePath->website($websiteCode, [$dimension]);
            }
        }

        $namespaces = array_values(array_unique($namespaces));
        sort($namespaces, SORT_STRING);
        return $namespaces;
    }

    /** @param array<string,mixed> $change */
    private function sanitizeChange(array $change): ?array
    {
        $key = trim((string)($change['key'] ?? ''));
        if ($key === '') {
            return null;
        }
        return [
            'key' => $key,
            'operation' => (string)($change['operation'] ?? ''),
            'before' => $this->sanitizeRow(is_array($change['old_row'] ?? null) ? $change['old_row'] : null),
            'after' => $this->sanitizeRow(is_array($change['new_row'] ?? null) ? $change['new_row'] : null),
        ];
    }

    /** @param array<string,mixed>|null $row */
    private function sanitizeRow(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        $value = $row[SystemConfig::schema_fields_VALUE] ?? null;
        return [
            'version' => (int)($row[SystemConfig::schema_fields_VERSION] ?? 0),
            'value_type' => (string)($row[SystemConfig::schema_fields_VALUE_TYPE] ?? ''),
            'is_sensitive' => (int)($row[SystemConfig::schema_fields_IS_SENSITIVE] ?? 0) === 1,
            'is_active' => (int)($row[SystemConfig::schema_fields_IS_ACTIVE] ?? 0) === 1,
            'value_sha256' => hash('sha256', is_scalar($value) || $value === null
                ? (string)$value
                : (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        ];
    }

    /**
     * @param list<array<string,mixed>> $records
     * @return array<string,mixed>
     */
    private function snapshot(
        string $module,
        string $area,
        string $scope,
        string $locale,
        array $records,
        string $side,
    ): array {
        $rows = [];
        foreach ($records as $record) {
            $rows[] = [
                'key' => $record['key'],
                'operation' => $record['operation'],
                'value' => $record[$side],
            ];
        }
        return [
            'module' => $module,
            'area' => $area,
            'scope' => $scope,
            'locale' => $locale,
            'rows' => $rows,
        ];
    }
}

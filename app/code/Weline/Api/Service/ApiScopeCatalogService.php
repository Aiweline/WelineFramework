<?php
declare(strict_types=1);

namespace Weline\Api\Service;

use Weline\Acl\Api\Scope\ScopeCatalogInterface;
use Weline\Api\Model\ApiAppInstallationScope;
use Weline\Framework\Manager\ObjectManager;

class ApiScopeCatalogService
{
    public function __construct(
        private readonly ScopeCatalogInterface $scopeCatalog,
    ) {
    }

    protected function newScopeModel(): ApiAppInstallationScope
    {
        return ObjectManager::getInstance(ApiAppInstallationScope::class, [], false);
    }

    public function listExposableSources(): array
    {
        $rows = $this->scopeCatalog->listExposableRows();

        $catalog = [];
        foreach ($rows as $row) {
            $row = $this->normalizeAclRow($row);
            $module = (string)($row[ScopeCatalogInterface::FIELD_MODULE] ?? '');
            $group = (string)($row[ScopeCatalogInterface::FIELD_SCOPE_GROUP] ?? '');
            $mode = (string)($row[ScopeCatalogInterface::FIELD_ACCESS_MODE] ?? ScopeCatalogInterface::ACCESS_MODE_EDIT);
            $catalog[$module][$group][$mode][] = $this->formatScopeRow($row);
        }

        return $catalog;
    }

    public function getExposableRowsBySourceIds(array $sourceIds): array
    {
        $sourceIds = $this->normalizeSourceIds($sourceIds);
        if (empty($sourceIds)) {
            return [];
        }

        $rows = $this->scopeCatalog->getExposableRowsBySourceIds($sourceIds);

        $bySourceId = [];
        foreach ($rows as $row) {
            $row = $this->normalizeAclRow($row);
            $bySourceId[(string)$row[ScopeCatalogInterface::FIELD_SOURCE_ID]] = $row;
        }

        return $bySourceId;
    }

    public function validateRequestedSources(array $sourceIds): array
    {
        $sourceIds = $this->normalizeSourceIds($sourceIds);
        if (empty($sourceIds)) {
            throw new \InvalidArgumentException(__('授权 scope 不能为空'));
        }

        $rows = $this->getExposableRowsBySourceIds($sourceIds);
        $missing = array_values(array_diff($sourceIds, array_keys($rows)));
        if (!empty($missing)) {
            throw new \InvalidArgumentException(__('以下 scope 不存在、已禁用或不允许外部授权：%{1}', [implode(', ', $missing)]));
        }

        return $rows;
    }

    public function getAclEntriesForInstallation(int $installationId): array
    {
        if ($installationId <= 0) {
            return [];
        }

        $scopeRows = $this->newScopeModel()->reset()
            ->where(ApiAppInstallationScope::schema_fields_INSTALLATION_ID, $installationId)
            ->select()
            ->fetchArray();
        if (empty($scopeRows)) {
            return [];
        }

        $sourceIds = array_values(array_unique(array_filter(array_map(
            static fn(array $row): string => (string)($row[ApiAppInstallationScope::schema_fields_SOURCE_ID] ?? ''),
            $scopeRows
        ))));
        if (empty($sourceIds)) {
            return [];
        }

        $aclRows = $this->scopeCatalog->getEnabledRowsBySourceIds($sourceIds);

        $scopeBySourceId = [];
        foreach ($scopeRows as $scopeRow) {
            $scopeBySourceId[(string)$scopeRow[ApiAppInstallationScope::schema_fields_SOURCE_ID]] = $scopeRow;
        }

        $entries = [];
        foreach ($aclRows as $row) {
            $sourceId = (string)($row[ScopeCatalogInterface::FIELD_SOURCE_ID] ?? '');
            if ($sourceId === '' || !isset($scopeBySourceId[$sourceId])) {
                continue;
            }
            $scopeRow = $scopeBySourceId[$sourceId];
            $row[ScopeCatalogInterface::FIELD_ACCESS_MODE] = $this->scopeCatalog->normalizeAccessMode(
                (string)($scopeRow[ApiAppInstallationScope::schema_fields_ACCESS_MODE] ?? ''),
                (string)($row[ScopeCatalogInterface::FIELD_METHOD] ?? '')
            );
            $row[ScopeCatalogInterface::FIELD_SCOPE_GROUP] = (string)($scopeRow[ApiAppInstallationScope::schema_fields_SCOPE_GROUP] ?? '');
            $entries[] = $this->normalizeAclRow($row);
        }

        return $entries;
    }

    private function normalizeSourceIds(array $sourceIds): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn(mixed $sourceId): string => trim((string)$sourceId),
            $sourceIds
        ))));
    }

    private function normalizeAclRow(array $row): array
    {
        $row[ScopeCatalogInterface::FIELD_ACCESS_MODE] = $this->scopeCatalog->normalizeAccessMode(
            (string)($row[ScopeCatalogInterface::FIELD_ACCESS_MODE] ?? ''),
            (string)($row[ScopeCatalogInterface::FIELD_METHOD] ?? '')
        );
        $row[ScopeCatalogInterface::FIELD_SCOPE_GROUP] = (string)($row[ScopeCatalogInterface::FIELD_SCOPE_GROUP] ?? '');
        $row[ScopeCatalogInterface::FIELD_API_EXPOSABLE] = (int)($row[ScopeCatalogInterface::FIELD_API_EXPOSABLE] ?? 0);
        return $row;
    }

    private function formatScopeRow(array $row): array
    {
        return [
            'source_id' => (string)($row[ScopeCatalogInterface::FIELD_SOURCE_ID] ?? ''),
            'source_name' => (string)($row[ScopeCatalogInterface::FIELD_SOURCE_NAME] ?? ''),
            'document' => (string)($row[ScopeCatalogInterface::FIELD_DOCUMENT] ?? ''),
            'module' => (string)($row[ScopeCatalogInterface::FIELD_MODULE] ?? ''),
            'scope_group' => (string)($row[ScopeCatalogInterface::FIELD_SCOPE_GROUP] ?? ''),
            'access_mode' => (string)($row[ScopeCatalogInterface::FIELD_ACCESS_MODE] ?? ScopeCatalogInterface::ACCESS_MODE_EDIT),
            'route' => (string)($row[ScopeCatalogInterface::FIELD_ROUTE] ?? ''),
            'method' => (string)($row[ScopeCatalogInterface::FIELD_METHOD] ?? ''),
        ];
    }
}

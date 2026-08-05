<?php

declare(strict_types=1);

namespace Weline\Acl\Service;

use Weline\Acl\Api\Authorization\ObjectAction;
use Weline\Acl\Api\Authorization\ObjectScopeGrantRecord;
use Weline\Acl\Api\Authorization\ObjectScopeGrantStoreInterface;
use Weline\Acl\Model\ObjectScopeGrant;
use Weline\Framework\Runtime\ScopeIdentity;

/**
 * 持久化对象 Scope 授权读取。
 *
 * 非法行（通配 *、All Sites 带写动作、残缺 Scope）在读取时跳过，fail-closed。
 */
final class ModelObjectScopeGrantStore implements ObjectScopeGrantStoreInterface
{
    public function __construct(
        private readonly ObjectScopeGrant $model,
    ) {
    }

    public function findByRole(int $roleId): array
    {
        if ($roleId <= 0) {
            return [];
        }
        $rows = (clone $this->model)->clearData()->reset()
            ->where(ObjectScopeGrant::schema_fields_ROLE_ID, $roleId)
            ->select()
            ->fetchArray();
        $out = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $record = $this->hydrate($row);
            if ($record !== null) {
                $out[] = $record;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ?ObjectScopeGrantRecord
    {
        $roleId = (int)($row[ObjectScopeGrant::schema_fields_ROLE_ID] ?? 0);
        if ($roleId <= 0) {
            return null;
        }
        $isAllSites = (int)($row[ObjectScopeGrant::schema_fields_IS_ALL_SITES] ?? 0) === 1;
        $actions = $this->decodeActions((string)($row[ObjectScopeGrant::schema_fields_ACTIONS] ?? ''));
        if ($actions === []) {
            return null;
        }
        $version = (int)($row[ObjectScopeGrant::schema_fields_GRANT_VERSION] ?? 0);
        if ($version <= 0) {
            return null;
        }

        if ($isAllSites) {
            foreach ($actions as $action) {
                if (!ObjectAction::isAllSitesReadable($action)) {
                    return null;
                }
            }

            return new ObjectScopeGrantRecord(
                $roleId,
                true,
                null,
                null,
                null,
                null,
                null,
                \array_values(\array_intersect($actions, ObjectAction::ALL_SITES_READ_ACTIONS)),
                $version,
            );
        }

        $kind = (string)($row[ObjectScopeGrant::schema_fields_SCOPE_KIND] ?? '');
        $websiteId = $row[ObjectScopeGrant::schema_fields_WEBSITE_ID] ?? null;
        $websiteCode = $this->nullableString($row[ObjectScopeGrant::schema_fields_WEBSITE_CODE] ?? null);
        $storeCode = $this->nullableString($row[ObjectScopeGrant::schema_fields_STORE_CODE] ?? null);
        $channelCode = $this->nullableString($row[ObjectScopeGrant::schema_fields_CHANNEL_CODE] ?? null);
        if ($websiteCode === '*' || $storeCode === '*' || $channelCode === '*') {
            return null;
        }
        $valid = match ($kind) {
            ScopeIdentity::KIND_GLOBAL => $websiteId === null
                && $websiteCode === null
                && $storeCode === null
                && $channelCode === null,
            ScopeIdentity::KIND_WEBSITE => $websiteCode !== null && $storeCode === null && $channelCode === null,
            ScopeIdentity::KIND_STORE => $websiteCode !== null && $storeCode !== null && $channelCode === null,
            ScopeIdentity::KIND_CHANNEL => $websiteCode !== null && $storeCode !== null && $channelCode !== null,
            default => false,
        };
        if (!$valid) {
            return null;
        }
        if ($kind !== ScopeIdentity::KIND_GLOBAL) {
            if (!\is_int($websiteId)
                && !(\is_string($websiteId) && \preg_match('/^(0|[1-9][0-9]*)$/D', $websiteId) === 1)
            ) {
                return null;
            }
            $websiteId = (int)$websiteId;
        } else {
            $websiteId = null;
        }

        $cleanActions = [];
        foreach ($actions as $action) {
            if ($action === ObjectAction::ALL_SITES || !ObjectAction::isKnown($action)) {
                continue;
            }
            $cleanActions[] = $action;
        }
        if ($cleanActions === []) {
            return null;
        }

        return new ObjectScopeGrantRecord(
            $roleId,
            false,
            $kind,
            $websiteId,
            $websiteCode,
            $storeCode,
            $channelCode,
            $cleanActions,
            $version,
        );
    }

    /**
     * @return list<string>
     */
    private function decodeActions(string $json): array
    {
        if ($json === '') {
            return [];
        }
        try {
            $decoded = \json_decode($json, true, 16, \JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return [];
        }
        if (!\is_array($decoded)) {
            return [];
        }
        $out = [];
        foreach ($decoded as $item) {
            if (\is_string($item) && ObjectAction::isKnown($item)) {
                $out[] = $item;
            }
        }

        return \array_values(\array_unique($out));
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (!\is_string($value)) {
            return null;
        }
        $value = \trim($value);

        return $value === '' ? null : $value;
    }
}

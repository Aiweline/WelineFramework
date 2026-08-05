<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Websites\Api\Catalog\Data\StoreSummary;
use Weline\Websites\Api\Catalog\StoreCatalogInterface;
use Weline\Websites\Model\Store;
use Weline\Websites\Model\Website;

final class StoreCatalog implements StoreCatalogInterface
{
    private const MAX_CATALOG_ID = 2147483647;

    public function __construct(
        private readonly Store $store,
        private readonly Website $website,
    ) {
    }

    public function byWebsite(int $websiteId): array
    {
        $this->assertWebsiteId($websiteId);
        $this->assertWebsiteExists($websiteId);

        $rows = $this->newStore()
            ->where(Store::schema_fields_WEBSITE_ID, $websiteId)
            ->order(Store::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();

        return $this->mapRows($rows, $websiteId);
    }

    public function byCode(int $websiteId, string $storeCode): ?StoreSummary
    {
        $this->assertWebsiteId($websiteId);
        $this->assertWebsiteExists($websiteId);

        $storeCode = Store::normalizeCode($storeCode);
        if ($storeCode === '') {
            return null;
        }
        $rows = $this->newStore()
            ->where(Store::schema_fields_WEBSITE_ID, $websiteId)
            ->where(Store::schema_fields_CODE, $storeCode)
            ->select()
            ->fetchArray();
        $mapped = $this->mapRows($rows, $websiteId);

        return $this->singleOrNull($mapped, __('店铺代码在同一 Website 下不唯一'));
    }

    public function byId(int $storeId): ?StoreSummary
    {
        if ($storeId <= 0) {
            return null;
        }
        $this->assertPositiveCatalogId($storeId, __('店铺 ID'));
        $rows = $this->newStore()
            ->where(Store::schema_fields_ID, $storeId)
            ->select()
            ->fetchArray();
        $mapped = $this->mapRows($rows);
        $this->assertParentWebsitesExist($mapped);

        return $this->singleOrNull($mapped, __('店铺 ID 不唯一'));
    }

    public function defaultStore(int $websiteId): ?StoreSummary
    {
        $this->assertWebsiteId($websiteId);
        $this->assertWebsiteExists($websiteId);

        $rows = $this->newStore()
            ->where(Store::schema_fields_WEBSITE_ID, $websiteId)
            ->where(Store::schema_fields_IS_DEFAULT, 1)
            ->select()
            ->fetchArray();
        $mapped = $this->mapRows($rows, $websiteId);
        $default = $this->singleOrNull($mapped, __('同一 Website 存在多个默认店铺'));
        if ($default !== null) {
            return $default;
        }
        $byCode = $this->byCode($websiteId, Store::CODE_DEFAULT);
        if ($byCode !== null && !$byCode->isDefault) {
            throw new \RuntimeException(__('code=default 的店铺缺少默认标记'));
        }
        return $byCode;
    }

    public function all(): array
    {
        $rows = $this->newStore()
            ->order(Store::schema_fields_ID, 'ASC')
            ->select()
            ->fetchArray();

        $mapped = $this->mapRows($rows);
        $this->assertParentWebsitesExist($mapped);

        return $mapped;
    }

    /**
     * @param mixed $rows
     * @return list<StoreSummary>
     */
    private function mapRows(mixed $rows, ?int $expectedWebsiteId = null): array
    {
        if (!\is_array($rows)) {
            throw new \RuntimeException((string)__('店铺目录查询结果必须是数组'));
        }

        $result = [];
        $seenIds = [];
        $seenCodes = [];
        $defaultStoreIds = [];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                throw new \RuntimeException((string)__('店铺目录包含非法数据行'));
            }

            $id = $this->requireIntegerField($row, Store::schema_fields_ID, 1);
            $websiteId = $this->requireIntegerField($row, Store::schema_fields_WEBSITE_ID, 0);
            if ($expectedWebsiteId !== null && $websiteId !== $expectedWebsiteId) {
                throw new \RuntimeException(
                    (string)__('店铺 %{1} 的 Website ID 与查询范围不一致', [$id])
                );
            }

            $code = $this->requireStringField($row, Store::schema_fields_CODE);
            if ($code === '' || Store::normalizeCode($code) !== $code) {
                throw new \RuntimeException(
                    (string)__('店铺 %{1} 的代码不是规范值', [$id])
                );
            }
            if (\mb_strlen($code, 'UTF-8') > Store::CODE_MAX_LENGTH) {
                throw new \RuntimeException(
                    (string)__('店铺代码不能超过 %{1} 个字符', [Store::CODE_MAX_LENGTH])
                );
            }
            $name = \trim($this->requireStringField($row, Store::schema_fields_NAME));
            if ($name === '') {
                throw new \RuntimeException((string)__('店铺 %{1} 缺少名称', [$id]));
            }
            if (\mb_strlen($name, 'UTF-8') > Store::NAME_MAX_LENGTH) {
                throw new \RuntimeException(
                    (string)__('店铺名称不能超过 %{1} 个字符', [Store::NAME_MAX_LENGTH])
                );
            }
            $storeMode = $this->requireStringField($row, Store::schema_fields_STORE_MODE);
            if (!\in_array($storeMode, Store::MODES, true)) {
                throw new \RuntimeException(
                    (string)__('店铺 %{1} 的模式无效：%{2}', [$id, $storeMode])
                );
            }
            $isDefault = $this->requireBinaryFlag($row, Store::schema_fields_IS_DEFAULT) === 1;
            $enabled = $this->requireBinaryFlag($row, Store::schema_fields_STATUS) === 1;
            $lifecycleStatus = $this->requireStringField($row, Store::schema_fields_LIFECYCLE_STATUS);
            if (!\in_array($lifecycleStatus, [Store::LIFECYCLE_ACTIVE, Store::LIFECYCLE_TOMBSTONE], true)) {
                throw new \RuntimeException(
                    (string)__('店铺 %{1} 的生命周期状态无效：%{2}', [$id, $lifecycleStatus])
                );
            }
            if (!\array_key_exists(Store::schema_fields_TOMBSTONED_AT, $row)) {
                throw new \RuntimeException(
                    (string)__('店铺目录数据缺少字段：%{1}', [Store::schema_fields_TOMBSTONED_AT])
                );
            }
            $tombstonedAt = $row[Store::schema_fields_TOMBSTONED_AT];
            if ($tombstonedAt !== null && !\is_string($tombstonedAt)) {
                throw new \RuntimeException((string)__('店铺 %{1} 的墓碑时间字段无效', [$id]));
            }
            if ($lifecycleStatus === Store::LIFECYCLE_ACTIVE && $tombstonedAt !== null) {
                throw new \RuntimeException((string)__('活动店铺 %{1} 不允许存在墓碑时间', [$id]));
            }
            if ($lifecycleStatus === Store::LIFECYCLE_TOMBSTONE) {
                if ($enabled) {
                    throw new \RuntimeException((string)__('墓碑店铺 %{1} 必须处于停用状态', [$id]));
                }
                $tombstonedAt = $this->requireUtcTimestamp($tombstonedAt, $id);
            }

            if (isset($seenIds[$id])) {
                throw new \RuntimeException((string)__('店铺目录存在重复 ID：%{1}', [$id]));
            }
            if (isset($seenCodes[$websiteId][$code])) {
                throw new \RuntimeException(
                    (string)__('Website %{1} 的店铺代码重复：%{2}', [$websiteId, $code])
                );
            }
            if ($isDefault && isset($defaultStoreIds[$websiteId])) {
                throw new \RuntimeException(
                    (string)__('Website %{1} 存在多个默认店铺', [$websiteId])
                );
            }
            if (($code === Store::CODE_DEFAULT) !== $isDefault) {
                throw new \RuntimeException(
                    (string)__('店铺 %{1} 的 default 代码与默认标记不一致', [$id])
                );
            }
            if ($isDefault && $lifecycleStatus !== Store::LIFECYCLE_ACTIVE) {
                throw new \RuntimeException(
                    (string)__('Website %{1} 的默认店铺不允许进入墓碑生命周期', [$websiteId])
                );
            }
            if ($isDefault && ($storeMode !== Store::MODE_NORMAL || !$enabled)) {
                throw new \RuntimeException(
                    (string)__('Website %{1} 的默认店铺必须为 normal 且启用', [$websiteId])
                );
            }

            if (!\array_key_exists(Store::schema_fields_URL, $row)) {
                throw new \RuntimeException((string)__('店铺 %{1} 缺少 URL 字段', [$id]));
            }
            $rawUrl = $row[Store::schema_fields_URL];
            if ($rawUrl !== null && !\is_string($rawUrl)) {
                throw new \RuntimeException((string)__('店铺 %{1} 的 URL 字段无效', [$id]));
            }
            $url = \trim($rawUrl ?? '');

            $seenIds[$id] = true;
            $seenCodes[$websiteId][$code] = true;
            if ($isDefault) {
                $defaultStoreIds[$websiteId] = $id;
            }
            $result[] = new StoreSummary(
                $id,
                $websiteId,
                $code,
                $name,
                $storeMode,
                $isDefault,
                $enabled,
                $lifecycleStatus,
                $tombstonedAt,
                $url !== '' ? $url : null,
            );
        }

        return $result;
    }

    /** @param array<string, mixed> $row */
    private function requireIntegerField(array $row, string $field, int $minimum): int
    {
        if (!\array_key_exists($field, $row)) {
            throw new \RuntimeException(
                (string)__('店铺目录数据缺少字段：%{1}', [$field])
            );
        }

        $value = $row[$field];
        if (\is_int($value)) {
            $normalized = $value;
        } elseif (\is_string($value) && \preg_match('/^(0|[1-9][0-9]*)$/D', $value) === 1) {
            $normalized = (int)$value;
            if ((string)$normalized !== $value) {
                throw new \RuntimeException(
                    (string)__('店铺目录字段 %{1} 超出整数范围', [$field])
                );
            }
        } else {
            throw new \RuntimeException(
                (string)__('店铺目录字段 %{1} 必须是规范整数', [$field])
            );
        }

        if ($normalized < $minimum) {
            throw new \RuntimeException(
                (string)__('店铺目录字段 %{1} 必须大于或等于 %{2}', [$field, $minimum])
            );
        }
        if ($normalized > self::MAX_CATALOG_ID) {
            throw new \RuntimeException(
                (string)__('店铺目录字段 %{1} 不能大于 %{2}', [$field, self::MAX_CATALOG_ID])
            );
        }

        return $normalized;
    }

    /** @param array<string, mixed> $row */
    private function requireStringField(array $row, string $field): string
    {
        if (!\array_key_exists($field, $row) || !\is_string($row[$field])) {
            throw new \RuntimeException(
                (string)__('店铺目录字段 %{1} 必须是字符串', [$field])
            );
        }

        return $row[$field];
    }

    /** @param array<string, mixed> $row */
    private function requireBinaryFlag(array $row, string $field): int
    {
        $value = $this->requireIntegerField($row, $field, 0);
        if ($value !== 0 && $value !== 1) {
            throw new \RuntimeException(
                (string)__('店铺目录字段 %{1} 只能是 0 或 1', [$field])
            );
        }

        return $value;
    }

    private function requireUtcTimestamp(?string $value, int $storeId): string
    {
        if ($value === null || \preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $value) !== 1) {
            throw new \RuntimeException((string)__('墓碑店铺 %{1} 缺少有效 UTC 墓碑时间', [$storeId]));
        }

        $timestamp = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $value,
            new \DateTimeZone('UTC'),
        );
        $errors = \DateTimeImmutable::getLastErrors();
        if ($timestamp === false
            || ($errors !== false && ($errors['warning_count'] !== 0 || $errors['error_count'] !== 0))
            || $timestamp->format('Y-m-d H:i:s') !== $value) {
            throw new \RuntimeException((string)__('墓碑店铺 %{1} 缺少有效 UTC 墓碑时间', [$storeId]));
        }

        return $value;
    }

    private function newStore(): Store
    {
        $store = clone $this->store;
        return $store->clearQuery()->clearData();
    }

    private function assertWebsiteId(int $websiteId): void
    {
        if ($websiteId < Website::ID_DEFAULT) {
            throw new \InvalidArgumentException((string)__('Website ID 不能为负数'));
        }
        if ($websiteId > self::MAX_CATALOG_ID) {
            throw new \InvalidArgumentException(
                (string)__('Website ID 不能大于 %{1}', [self::MAX_CATALOG_ID])
            );
        }
    }

    private function assertPositiveCatalogId(int $id, string $label): void
    {
        if ($id > self::MAX_CATALOG_ID) {
            throw new \InvalidArgumentException(
                (string)__('%{1} 不能大于 %{2}', [$label, self::MAX_CATALOG_ID])
            );
        }
    }

    /** @param list<StoreSummary> $stores */
    private function assertParentWebsitesExist(array $stores): void
    {
        $checked = [];
        foreach ($stores as $store) {
            if (isset($checked[$store->websiteId])) {
                continue;
            }
            $this->assertWebsiteExists($store->websiteId);
            $checked[$store->websiteId] = true;
        }
    }

    private function assertWebsiteExists(int $websiteId): void
    {
        $website = clone $this->website;
        $row = $website->clearQuery()->clearData()
            ->where(Website::schema_fields_ID, $websiteId)
            ->find()
            ->fetchArray();
        if (!\is_array($row) || !\array_key_exists(Website::schema_fields_ID, $row)) {
            throw new \RuntimeException(
                (string)__('店铺目录引用了不存在的父 Website：%{1}', [$websiteId])
            );
        }
        if ((int)$row[Website::schema_fields_ID] !== $websiteId) {
            throw new \RuntimeException(
                (string)__('父 Website ID 与店铺目录查询范围不一致：%{1}', [$websiteId])
            );
        }
    }

    /** @param list<StoreSummary> $stores */
    private function singleOrNull(array $stores, string $error): ?StoreSummary
    {
        if (count($stores) > 1) {
            throw new \RuntimeException($error);
        }
        return $stores[0] ?? null;
    }
}

<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Acl\Model\Acl;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RequestContext;
use Weline\Websites\Model\Website;
use Weline\Websites\Model\WebsiteAclGrant;

/**
 * 站级 ACL 授权包读写与有效权限交集。
 */
final class WebsiteAclGrantService
{
    /**
     * Request-local memo only. Cleared by RequestResetter / ProcessCacheResetter.
     * Never rely on this surviving across WLS worker requests: an empty [] entry
     * must not outlive a concurrent save on another worker.
     *
     * @var array<int, list<string>>
     */
    private static array $sourceIdCache = [];

    public function isDefaultWebsite(?int $websiteId = null): bool
    {
        return $this->resolveWebsiteId($websiteId) === Website::ID_DEFAULT;
    }

    public function currentWebsiteId(): int
    {
        try {
            return max(0, (int)RequestContext::getWelineWebsiteId());
        } catch (\Throwable) {
            return Website::ID_DEFAULT;
        }
    }

    /**
     * @return list<string>
     */
    public function getGrantedSourceIds(int $websiteId): array
    {
        $websiteId = max(0, $websiteId);
        if ($websiteId === Website::ID_DEFAULT) {
            return [];
        }
        if (\array_key_exists($websiteId, self::$sourceIdCache)) {
            return self::$sourceIdCache[$websiteId];
        }

        /** @var WebsiteAclGrant $model */
        $model = ObjectManager::getInstance(WebsiteAclGrant::class, [], false);
        $rows = $model->reset()
            ->fields(WebsiteAclGrant::schema_fields_SOURCE_ID)
            ->where(WebsiteAclGrant::schema_fields_WEBSITE_ID, $websiteId)
            ->select()
            ->fetchArray();

        $ids = [];
        foreach ($rows as $row) {
            $sourceId = \trim((string)($row[WebsiteAclGrant::schema_fields_SOURCE_ID] ?? ''));
            if ($sourceId !== '') {
                $ids[$sourceId] = true;
            }
        }
        $list = \array_keys($ids);
        self::$sourceIdCache[$websiteId] = $list;

        return $list;
    }

    public function hasAnyGrant(int $websiteId): bool
    {
        return $this->getGrantedSourceIds($websiteId) !== [];
    }

    /**
     * 整包替换授权。禁止 website_id=0。
     *
     * @param list<string> $sourceIds
     */
    public function replaceGrants(int $websiteId, array $sourceIds): void
    {
        $websiteId = max(0, $websiteId);
        if ($websiteId === Website::ID_DEFAULT) {
            throw new \InvalidArgumentException((string)__('默认站不使用站级 ACL 授权包'));
        }

        $normalized = [];
        foreach ($sourceIds as $sourceId) {
            $sourceId = \trim((string)$sourceId);
            if ($sourceId === '' || \str_starts_with($sourceId, 'tag:')) {
                continue;
            }
            $normalized[$sourceId] = true;
        }
        $finalIds = \array_keys($normalized);

        /** @var WebsiteAclGrant $model */
        $model = ObjectManager::getInstance(WebsiteAclGrant::class, [], false);
        $model->beginTransaction();
        try {
            $model->reset()
                ->where(WebsiteAclGrant::schema_fields_WEBSITE_ID, $websiteId)
                ->delete()
                ->fetch();
            if ($finalIds !== []) {
                $now = \date('Y-m-d H:i:s');
                $rows = [];
                foreach ($finalIds as $sourceId) {
                    $rows[] = [
                        WebsiteAclGrant::schema_fields_WEBSITE_ID => $websiteId,
                        WebsiteAclGrant::schema_fields_SOURCE_ID => $sourceId,
                        WebsiteAclGrant::schema_fields_CREATED_AT => $now,
                    ];
                }
                $model->reset()->insert($rows, [
                    WebsiteAclGrant::schema_fields_WEBSITE_ID,
                    WebsiteAclGrant::schema_fields_SOURCE_ID,
                ])->fetch();
            }
            $model->commit();
        } catch (\Throwable $e) {
            $model->rollBack();
            throw $e;
        }

        // Drop memo so this worker re-reads after write; other workers rely on
        // RequestResetter / ProcessCacheResetter (and w_cache('acl')->clear()).
        unset(self::$sourceIdCache[$websiteId]);
    }

    /**
     * 非默认站：裁剪角色 ACL 行；空包 → 空数组。
     * 默认站：原样返回。
     * role_id=1（超管）：无站点级区分，原样返回（不走站授权包硬顶）。
     *
     * @param list<array<string, mixed>> $entries
     * @return list<array<string, mixed>>
     */
    public function filterRoleAclEntries(array $entries, int $roleId, ?int $websiteId = null): array
    {
        if ($roleId === 1) {
            return $entries;
        }

        $websiteId = $this->resolveWebsiteId($websiteId);
        if ($websiteId === Website::ID_DEFAULT) {
            return $entries;
        }

        $granted = $this->getGrantedSourceIds($websiteId);
        if ($granted === []) {
            return [];
        }
        $grantSet = \array_fill_keys($granted, true);

        $filtered = [];
        foreach ($entries as $row) {
            $sourceId = \trim((string)($row[Acl::schema_fields_SOURCE_ID] ?? $row['source_id'] ?? ''));
            if ($sourceId !== '' && isset($grantSet[$sourceId])) {
                $filtered[] = $row;
            }
        }

        return $filtered;
    }

    /**
     * 校验拟写入的 source_id 是否全部落在站授权包内。
     *
     * @param list<string> $sourceIds
     * @return list<string> 越权的 source_id
     */
    public function findSourcesOutsideGrant(int $websiteId, array $sourceIds): array
    {
        $websiteId = max(0, $websiteId);
        if ($websiteId === Website::ID_DEFAULT) {
            return [];
        }
        $grantSet = \array_fill_keys($this->getGrantedSourceIds($websiteId), true);
        $outside = [];
        foreach ($sourceIds as $sourceId) {
            $sourceId = \trim((string)$sourceId);
            if ($sourceId === '' || \str_starts_with($sourceId, 'tag:')) {
                continue;
            }
            if (!isset($grantSet[$sourceId])) {
                $outside[] = $sourceId;
            }
        }

        return $outside;
    }

    public static function clearRequestCache(): void
    {
        self::$sourceIdCache = [];
    }

    private function resolveWebsiteId(?int $websiteId): int
    {
        if ($websiteId !== null) {
            return max(0, $websiteId);
        }

        return $this->currentWebsiteId();
    }
}

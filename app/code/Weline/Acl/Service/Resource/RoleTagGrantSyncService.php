<?php

declare(strict_types=1);

namespace Weline\Acl\Service\Resource;

use Weline\Acl\Model\Acl;
use Weline\Acl\Model\RoleAccess;
use Weline\Acl\Model\RoleTagGrant;
use Weline\Framework\Authorization\Resource\SourceIdParser;
use Weline\Framework\Manager\ObjectManager;

/**
 * Expands role_tag_grant subscriptions into role_access (add-only, D-1).
 * Skips role_id=1 (D-7).
 */
final class RoleTagGrantSyncService
{
    public const SUPER_ADMIN_ROLE_ID = 1;

    /**
     * @param list<string>|null $touchedModules when set, only sync sources from those modules
     * @param list<int>|null $roleIds when set, only materialize grants for these roles
     * @return int inserted role_access rows
     */
    public function syncAddOnly(?array $touchedModules = null, ?array $roleIds = null): int
    {
        $roleFilter = $roleIds === null ? null : \array_fill_keys(
            \array_values(\array_filter(
                \array_map('intval', $roleIds),
                static fn(int $roleId): bool => $roleId > 0,
            )),
            true,
        );
        /** @var RoleTagGrant $grantModel */
        $grantModel = ObjectManager::getInstance(RoleTagGrant::class);
        $grants = $grantModel->reset()->select()->fetchArray();
        if ($grants === []) {
            return 0;
        }

        /** @var Acl $aclModel */
        $aclModel = ObjectManager::getInstance(Acl::class);
        $allAcl = $aclModel->reset()
            ->fields([Acl::schema_fields_SOURCE_ID, Acl::schema_fields_MODULE, Acl::schema_fields_RESOURCE_METADATA])
            ->select()
            ->fetchArray();

        $sourcesByTagPath = [];
        foreach ($allAcl as $row) {
            $sourceId = (string)($row[Acl::schema_fields_SOURCE_ID] ?? '');
            $module = (string)($row[Acl::schema_fields_MODULE] ?? '');
            if ($sourceId === '') {
                continue;
            }
            if ($touchedModules !== null && $touchedModules !== [] && !\in_array($module, $touchedModules, true)) {
                // Also allow Module:: prefix match
                $matched = false;
                foreach ($touchedModules as $touched) {
                    if (\str_starts_with($sourceId, $touched . '::')) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    continue;
                }
            }
            $parsed = SourceIdParser::parse($sourceId);
            $tags = $parsed['tags'] ?? [];
            if ($tags === []) {
                $meta = \json_decode((string)($row[Acl::schema_fields_RESOURCE_METADATA] ?? ''), true);
                if (\is_array($meta) && \is_array($meta['tags'] ?? null)) {
                    $tags = \array_values(\array_map('strval', $meta['tags']));
                }
            }
            if ($tags === []) {
                continue;
            }
            // Every prefix path of the tag list
            for ($i = 1, $n = \count($tags); $i <= $n; ++$i) {
                $path = \implode(':', \array_slice($tags, 0, $i));
                $sourcesByTagPath[$path][$sourceId] = true;
            }
        }

        $inserted = 0;
        foreach ($grants as $grant) {
            $roleId = (int)($grant[RoleTagGrant::schema_fields_ROLE_ID] ?? 0);
            $tagPath = \trim((string)($grant[RoleTagGrant::schema_fields_TAG_PATH] ?? ''));
            if ($roleId <= 0 || $roleId === self::SUPER_ADMIN_ROLE_ID || $tagPath === '') {
                continue;
            }
            if ($roleFilter !== null && !isset($roleFilter[$roleId])) {
                continue;
            }
            $sources = \array_keys($sourcesByTagPath[$tagPath] ?? []);
            $rows = [];
            foreach ($sources as $sourceId) {
                /** @var RoleAccess $probe */
                $probe = ObjectManager::getInstance(RoleAccess::class, [], false);
                $exists = $probe->reset()
                    ->where(RoleAccess::schema_fields_ROLE_ID, $roleId)
                    ->where(RoleAccess::schema_fields_SOURCE_ID, $sourceId)
                    ->find()
                    ->fetch();
                if ((string)$exists->getData(RoleAccess::schema_fields_SOURCE_ID) === $sourceId) {
                    continue;
                }
                $rows[] = [
                    RoleAccess::schema_fields_ROLE_ID => $roleId,
                    RoleAccess::schema_fields_SOURCE_ID => $sourceId,
                ];
            }
            if ($rows === []) {
                continue;
            }
            /** @var RoleAccess $writer */
            $writer = ObjectManager::getInstance(RoleAccess::class, [], false);
            $writer->reset()->insert($rows, [
                RoleAccess::schema_fields_ROLE_ID,
                RoleAccess::schema_fields_SOURCE_ID,
            ])->fetch();
            $inserted += \count($rows);
        }
        return $inserted;
    }
}

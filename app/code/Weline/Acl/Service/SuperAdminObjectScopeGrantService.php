<?php

declare(strict_types=1);

namespace Weline\Acl\Service;

use Weline\Acl\Api\Authorization\ObjectAction;
use Weline\Acl\Model\ObjectScopeGrant;
use Weline\Acl\Model\Role;
use Weline\Framework\Runtime\ScopeIdentity;

/**
 * 超管（role_id=1）对象 Scope 授权增量补齐。
 *
 * All Sites 永远只读；写动作必须落到 Global / 具体 Website 行。
 * Website 级写授权覆盖同站 store/channel（见 ObjectScopeGrantRecord::covers）。
 */
final class SuperAdminObjectScopeGrantService
{
    public const GRANT_VERSION = 1;

    public function __construct(
        private readonly ObjectScopeGrant $objectScopeGrant,
    ) {
    }

    /**
     * @return int 新写入或修正的授权行数
     */
    public function ensureBaselineGrants(): int
    {
        $changed = 0;
        if ($this->ensureAllSitesReadGrant()) {
            ++$changed;
        }
        if ($this->ensureGlobalWriteGrant()) {
            ++$changed;
        }
        foreach ($this->listWebsiteTargets() as $website) {
            if ($this->ensureWebsiteWriteGrant($website['website_id'], $website['website_code'])) {
                ++$changed;
            }
        }

        return $changed;
    }

    public function ensureAllSitesReadGrant(): bool
    {
        if ($this->hasAllSitesGrant()) {
            return false;
        }
        $this->insertGrant([
            ObjectScopeGrant::schema_fields_ROLE_ID => Role::ID_SUPER_ADMIN,
            ObjectScopeGrant::schema_fields_IS_ALL_SITES => 1,
            ObjectScopeGrant::schema_fields_SCOPE_KIND => null,
            ObjectScopeGrant::schema_fields_WEBSITE_ID => null,
            ObjectScopeGrant::schema_fields_WEBSITE_CODE => null,
            ObjectScopeGrant::schema_fields_STORE_CODE => null,
            ObjectScopeGrant::schema_fields_CHANNEL_CODE => null,
            ObjectScopeGrant::schema_fields_ACTIONS => $this->encodeActions(ObjectAction::ALL_SITES_READ_ACTIONS),
            ObjectScopeGrant::schema_fields_GRANT_VERSION => self::GRANT_VERSION,
        ]);

        return true;
    }

    public function ensureGlobalWriteGrant(): bool
    {
        if ($this->hasScopedGrant(ScopeIdentity::KIND_GLOBAL, null, null)) {
            return false;
        }
        $this->insertGrant([
            ObjectScopeGrant::schema_fields_ROLE_ID => Role::ID_SUPER_ADMIN,
            ObjectScopeGrant::schema_fields_IS_ALL_SITES => 0,
            ObjectScopeGrant::schema_fields_SCOPE_KIND => ScopeIdentity::KIND_GLOBAL,
            ObjectScopeGrant::schema_fields_WEBSITE_ID => null,
            ObjectScopeGrant::schema_fields_WEBSITE_CODE => null,
            ObjectScopeGrant::schema_fields_STORE_CODE => null,
            ObjectScopeGrant::schema_fields_CHANNEL_CODE => null,
            ObjectScopeGrant::schema_fields_ACTIONS => $this->encodeActions($this->fullWriteActions()),
            ObjectScopeGrant::schema_fields_GRANT_VERSION => self::GRANT_VERSION,
        ]);

        return true;
    }

    /**
     * 确保超管对指定网站拥有 Website 级写授权（覆盖该站 store/channel）。
     *
     * @return bool true=新写入或 code 漂移已修正
     */
    public function ensureWebsiteWriteGrant(int $websiteId, string $websiteCode): bool
    {
        if ($websiteId < 0) {
            return false;
        }
        $websiteCode = \trim($websiteCode);
        if ($websiteCode === '' || $websiteCode === '*') {
            return false;
        }

        $existing = $this->findWebsiteGrantRow($websiteId);
        if ($existing !== null) {
            $currentCode = \trim((string)($existing[ObjectScopeGrant::schema_fields_WEBSITE_CODE] ?? ''));
            if ($currentCode === $websiteCode) {
                return false;
            }
            $grantId = (int)($existing[ObjectScopeGrant::schema_fields_ID] ?? 0);
            if ($grantId <= 0) {
                return false;
            }
            $this->objectScopeGrant->clear()
                ->where(ObjectScopeGrant::schema_fields_ID, $grantId)
                ->update([
                    ObjectScopeGrant::schema_fields_WEBSITE_CODE => $websiteCode,
                ])
                ->fetch();

            return true;
        }

        $this->insertGrant([
            ObjectScopeGrant::schema_fields_ROLE_ID => Role::ID_SUPER_ADMIN,
            ObjectScopeGrant::schema_fields_IS_ALL_SITES => 0,
            ObjectScopeGrant::schema_fields_SCOPE_KIND => ScopeIdentity::KIND_WEBSITE,
            ObjectScopeGrant::schema_fields_WEBSITE_ID => $websiteId,
            ObjectScopeGrant::schema_fields_WEBSITE_CODE => $websiteCode,
            ObjectScopeGrant::schema_fields_STORE_CODE => null,
            ObjectScopeGrant::schema_fields_CHANNEL_CODE => null,
            ObjectScopeGrant::schema_fields_ACTIONS => $this->encodeActions($this->fullWriteActions()),
            ObjectScopeGrant::schema_fields_GRANT_VERSION => self::GRANT_VERSION,
        ]);

        return true;
    }

    /**
     * @return list<array{website_id:int,website_code:string}>
     */
    public function listWebsiteTargets(): array
    {
        $targets = [
            ['website_id' => 0, 'website_code' => 'default'],
        ];
        if (!\class_exists(\Weline\Websites\Model\Website::class)) {
            return $targets;
        }

        try {
            /** @var \Weline\Websites\Model\Website $website */
            $website = \Weline\Framework\Manager\ObjectManager::getInstance(\Weline\Websites\Model\Website::class);
            $rows = $website->clear()->select()->fetchArray();
        } catch (\Throwable) {
            return $targets;
        }
        if (!\is_array($rows)) {
            return $targets;
        }

        $seen = [0 => true];
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $id = (int)($row[\Weline\Websites\Model\Website::schema_fields_ID] ?? -1);
            $code = \trim((string)($row[\Weline\Websites\Model\Website::schema_fields_CODE] ?? ''));
            if ($id < 0 || $code === '' || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $targets[] = ['website_id' => $id, 'website_code' => $code];
        }

        return $targets;
    }

    /**
     * @return list<string>
     */
    private function fullWriteActions(): array
    {
        return \array_values(\array_diff(ObjectAction::ALL, [ObjectAction::ALL_SITES]));
    }

    /**
     * @param list<string> $actions
     */
    private function encodeActions(array $actions): string
    {
        return \json_encode(
            \array_values($actions),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
    }

    private function hasAllSitesGrant(): bool
    {
        $row = $this->objectScopeGrant->clear()
            ->where(ObjectScopeGrant::schema_fields_ROLE_ID, Role::ID_SUPER_ADMIN)
            ->where(ObjectScopeGrant::schema_fields_IS_ALL_SITES, 1)
            ->find()
            ->fetch();

        return (int)$row->getId() > 0;
    }

    private function hasScopedGrant(string $scopeKind, ?int $websiteId, ?string $websiteCode): bool
    {
        $query = $this->objectScopeGrant->clear()
            ->where(ObjectScopeGrant::schema_fields_ROLE_ID, Role::ID_SUPER_ADMIN)
            ->where(ObjectScopeGrant::schema_fields_IS_ALL_SITES, 0)
            ->where(ObjectScopeGrant::schema_fields_SCOPE_KIND, $scopeKind);

        if ($websiteId === null) {
            $query->where(ObjectScopeGrant::schema_fields_WEBSITE_ID, null, 'is null');
        } else {
            $query->where(ObjectScopeGrant::schema_fields_WEBSITE_ID, $websiteId);
        }

        if ($websiteCode === null) {
            $query->where(ObjectScopeGrant::schema_fields_WEBSITE_CODE, null, 'is null');
        } else {
            $query->where(ObjectScopeGrant::schema_fields_WEBSITE_CODE, $websiteCode);
        }

        $query->where(ObjectScopeGrant::schema_fields_STORE_CODE, null, 'is null')
            ->where(ObjectScopeGrant::schema_fields_CHANNEL_CODE, null, 'is null');

        $row = $query->find()->fetch();

        return (int)$row->getId() > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findWebsiteGrantRow(int $websiteId): ?array
    {
        $row = $this->objectScopeGrant->clear()
            ->where(ObjectScopeGrant::schema_fields_ROLE_ID, Role::ID_SUPER_ADMIN)
            ->where(ObjectScopeGrant::schema_fields_IS_ALL_SITES, 0)
            ->where(ObjectScopeGrant::schema_fields_SCOPE_KIND, ScopeIdentity::KIND_WEBSITE)
            ->where(ObjectScopeGrant::schema_fields_WEBSITE_ID, $websiteId)
            ->where(ObjectScopeGrant::schema_fields_STORE_CODE, null, 'is null')
            ->where(ObjectScopeGrant::schema_fields_CHANNEL_CODE, null, 'is null')
            ->find()
            ->fetch();
        if ((int)$row->getId() <= 0) {
            return null;
        }

        return $row->getData();
    }

    /**
     * @param array<string, mixed> $data
     */
    private function insertGrant(array $data): void
    {
        $this->objectScopeGrant->clear()->setData($data)->save(true);
    }
}

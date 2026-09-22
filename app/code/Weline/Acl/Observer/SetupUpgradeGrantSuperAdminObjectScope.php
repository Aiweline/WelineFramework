<?php

declare(strict_types=1);

namespace Weline\Acl\Observer;

use Weline\Acl\Api\Authorization\ObjectAction;
use Weline\Acl\Model\ObjectScopeGrant;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Runtime\ScopeIdentity;

/**
 * 系统升级完成后，为超级管理员（role_id=1）补齐对象 Scope 授权。
 *
 * 路由 RBAC 已在 {@see SetupUpgradeGrantSuperAdmin} 中增量授予；对象 Scope ACL 独立判定，
 * 默认站超管需显式授权行才能打开统一配置中心等对象 Scope 页面。
 */
final class SetupUpgradeGrantSuperAdminObjectScope implements ObserverInterface
{
    private const SUPER_ADMIN_ROLE_ID = \Weline\Acl\Model\Role::ID_SUPER_ADMIN;
    private const GRANT_VERSION = 1;

    public function __construct(
        private readonly ObjectScopeGrant $objectScopeGrant,
        private readonly Printing $printing,
    ) {
    }

    public function execute(Event &$event): void
    {
        $isPartialUpgrade = $event->getData('is_partial_upgrade') ?? false;
        $routeOnly = $event->getData('route_only') ?? false;
        $modelOnly = $event->getData('model_only') ?? false;
        if ($isPartialUpgrade || $routeOnly || $modelOnly) {
            return;
        }

        try {
            $inserted = $this->ensureSuperAdminObjectScopeGrants();
            if ($inserted === 0) {
                if (\defined('DEV') && DEV) {
                    $this->printing->note(__('超级管理员已拥有对象 Scope 授权，无需追加。'));
                }

                return;
            }

            $this->printing->success(
                __('已为超级管理员（role_id=1）追加 %{1} 条对象 Scope 授权。', [$inserted]),
            );
        } catch (\Throwable $exception) {
            if (\defined('DEV') && DEV) {
                $this->printing->warning(
                    __('为超级管理员授予对象 Scope 授权时出错：%{1}', [$exception->getMessage()]),
                );
            }
        }
    }

    private function ensureSuperAdminObjectScopeGrants(): int
    {
        $inserted = 0;
        $fullActions = \json_encode(
            \array_values(\array_diff(ObjectAction::ALL, [ObjectAction::ALL_SITES])),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
        $readActions = \json_encode(
            ObjectAction::ALL_SITES_READ_ACTIONS,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );

        if (!$this->hasAllSitesGrant()) {
            $this->insertGrant([
                ObjectScopeGrant::schema_fields_ROLE_ID => self::SUPER_ADMIN_ROLE_ID,
                ObjectScopeGrant::schema_fields_IS_ALL_SITES => 1,
                ObjectScopeGrant::schema_fields_SCOPE_KIND => null,
                ObjectScopeGrant::schema_fields_WEBSITE_ID => null,
                ObjectScopeGrant::schema_fields_WEBSITE_CODE => null,
                ObjectScopeGrant::schema_fields_STORE_CODE => null,
                ObjectScopeGrant::schema_fields_CHANNEL_CODE => null,
                ObjectScopeGrant::schema_fields_ACTIONS => $readActions,
                ObjectScopeGrant::schema_fields_GRANT_VERSION => self::GRANT_VERSION,
            ]);
            ++$inserted;
        }

        if (!$this->hasScopedGrant(ScopeIdentity::KIND_GLOBAL, null, null, null, null)) {
            $this->insertGrant([
                ObjectScopeGrant::schema_fields_ROLE_ID => self::SUPER_ADMIN_ROLE_ID,
                ObjectScopeGrant::schema_fields_IS_ALL_SITES => 0,
                ObjectScopeGrant::schema_fields_SCOPE_KIND => ScopeIdentity::KIND_GLOBAL,
                ObjectScopeGrant::schema_fields_WEBSITE_ID => null,
                ObjectScopeGrant::schema_fields_WEBSITE_CODE => null,
                ObjectScopeGrant::schema_fields_STORE_CODE => null,
                ObjectScopeGrant::schema_fields_CHANNEL_CODE => null,
                ObjectScopeGrant::schema_fields_ACTIONS => $fullActions,
                ObjectScopeGrant::schema_fields_GRANT_VERSION => self::GRANT_VERSION,
            ]);
            ++$inserted;
        }

        if (!$this->hasScopedGrant(ScopeIdentity::KIND_WEBSITE, 0, 'default', null, null)) {
            $this->insertGrant([
                ObjectScopeGrant::schema_fields_ROLE_ID => self::SUPER_ADMIN_ROLE_ID,
                ObjectScopeGrant::schema_fields_IS_ALL_SITES => 0,
                ObjectScopeGrant::schema_fields_SCOPE_KIND => ScopeIdentity::KIND_WEBSITE,
                ObjectScopeGrant::schema_fields_WEBSITE_ID => 0,
                ObjectScopeGrant::schema_fields_WEBSITE_CODE => 'default',
                ObjectScopeGrant::schema_fields_STORE_CODE => null,
                ObjectScopeGrant::schema_fields_CHANNEL_CODE => null,
                ObjectScopeGrant::schema_fields_ACTIONS => $fullActions,
                ObjectScopeGrant::schema_fields_GRANT_VERSION => self::GRANT_VERSION,
            ]);
            ++$inserted;
        }

        return $inserted;
    }

    private function hasAllSitesGrant(): bool
    {
        $row = $this->objectScopeGrant->clear()
            ->where(ObjectScopeGrant::schema_fields_ROLE_ID, self::SUPER_ADMIN_ROLE_ID)
            ->where(ObjectScopeGrant::schema_fields_IS_ALL_SITES, 1)
            ->find()
            ->fetch();

        return (int) $row->getId() > 0;
    }

    private function hasScopedGrant(
        string $scopeKind,
        ?int $websiteId,
        ?string $websiteCode,
        ?string $storeCode,
        ?string $channelCode,
    ): bool {
        $query = $this->objectScopeGrant->clear()
            ->where(ObjectScopeGrant::schema_fields_ROLE_ID, self::SUPER_ADMIN_ROLE_ID)
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

        if ($storeCode === null) {
            $query->where(ObjectScopeGrant::schema_fields_STORE_CODE, null, 'is null');
        } else {
            $query->where(ObjectScopeGrant::schema_fields_STORE_CODE, $storeCode);
        }

        if ($channelCode === null) {
            $query->where(ObjectScopeGrant::schema_fields_CHANNEL_CODE, null, 'is null');
        } else {
            $query->where(ObjectScopeGrant::schema_fields_CHANNEL_CODE, $channelCode);
        }

        $row = $query->find()->fetch();

        return (int) $row->getId() > 0;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function insertGrant(array $data): void
    {
        $this->objectScopeGrant->clear()->setData($data)->save(true);
    }
}

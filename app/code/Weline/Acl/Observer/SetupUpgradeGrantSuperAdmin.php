<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Acl\Observer;

use Weline\Acl\Model\Acl;
use Weline\Acl\Model\RoleAccess;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

/**
 * 系统升级完成后，为超级管理员（role_id=1）授予当前 ACL 表中的全部权限。
 *
 * 场景：setup:upgrade 会清空并重新收集 ACL 表，但不会自动为 role_id 1 写入 role_access，
 * 导致“用户没有分配角色”或“没有任何权限”等提示。本观察者在 upgrade_after 时
 * 将当前所有 source_id 授权给 role_id 1，仅做增量插入（已存在的 role_id+source_id 不重复插入）。
 */
class SetupUpgradeGrantSuperAdmin implements ObserverInterface
{
    private const SUPER_ADMIN_ROLE_ID = 1;

    public function __construct(
        private Acl $acl,
        private RoleAccess $roleAccess,
        private Printing $printing
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $isPartialUpgrade = $event->getData('is_partial_upgrade') ?? false;
        $routeOnly = $event->getData('route_only') ?? false;
        $modelOnly = $event->getData('model_only') ?? false;
        if ($isPartialUpgrade || $routeOnly || $modelOnly) {
            return;
        }

        try {
            $allSourceIds = $this->acl->reset()
                ->fields(Acl::schema_fields_SOURCE_ID)
                ->select()
                ->fetchArray();
            $allSourceIds = array_column($allSourceIds, Acl::schema_fields_SOURCE_ID);
            $allSourceIds = array_values(array_filter(array_unique($allSourceIds)));
            if (empty($allSourceIds)) {
                return;
            }

            $existing = $this->roleAccess->reset()
                ->where(RoleAccess::schema_fields_ROLE_ID, self::SUPER_ADMIN_ROLE_ID)
                ->fields(RoleAccess::schema_fields_SOURCE_ID)
                ->select()
                ->fetchArray();
            $existingSourceIds = array_flip(array_column($existing, RoleAccess::schema_fields_SOURCE_ID));

            $toInsert = [];
            foreach ($allSourceIds as $sourceId) {
                if (!isset($existingSourceIds[$sourceId])) {
                    $toInsert[] = [
                        RoleAccess::schema_fields_ROLE_ID => self::SUPER_ADMIN_ROLE_ID,
                        RoleAccess::schema_fields_SOURCE_ID => $sourceId,
                    ];
                }
            }
            if (empty($toInsert)) {
                if (defined('DEV') && DEV) {
                    $this->printing->note(__('超级管理员已拥有全部 ACL 权限，无需追加。'));
                }
                w_cache('acl')->clear();
                return;
            }

            $this->roleAccess->beginTransaction();
            try {
                $this->roleAccess->reset()->insert(
                    $toInsert,
                    [RoleAccess::schema_fields_ROLE_ID, RoleAccess::schema_fields_SOURCE_ID]
                )->fetch();
                $this->roleAccess->commit();
            } catch (\Throwable $e) {
                $this->roleAccess->rollBack();
                throw $e;
            }
            w_cache('acl')->clear();
            $this->printing->success(__('已为超级管理员（role_id=1）授予 %{1} 条新权限。', [count($toInsert)]));
        } catch (\Throwable $e) {
            if (defined('DEV') && DEV) {
                $this->printing->warning(__('为超级管理员授予权限时出错：%{1}', [$e->getMessage()]));
            }
        }
    }
}

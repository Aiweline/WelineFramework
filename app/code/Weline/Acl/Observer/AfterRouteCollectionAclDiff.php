<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 */

namespace Weline\Acl\Observer;

use Weline\Acl\Model\Acl;
use Weline\Acl\Model\RoleAccess;
use Weline\Framework\App\Env;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * 路由收集后执行 ACL diff：删除已卸载模块的 type=pc（控制器/方法权限）及对应 role_access。
 * 与菜单 diff（MenuCollector）对称，菜单在 before_route_collection 做 diff，ACL 在路由收集阶段收集，故在此做 diff。
 */
class AfterRouteCollectionAclDiff implements ObserverInterface
{
    /** 控制器/方法 ACL 的类型（路由阶段收集） */
    private const TYPE_PC = 'pc';

    public function __construct(
        private Acl $acl
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $installedModules = array_keys(Env::getInstance()->getModuleList());
        if (empty($installedModules)) {
            return;
        }

        // 路由阶段只写入 type=pc，只清理 type=pc 中 module 不在已安装列表的
        $orphanRows = $this->acl->reset()
            ->where(Acl::schema_fields_TYPE, self::TYPE_PC)
            ->where(Acl::schema_fields_ACL_ORIGIN, Acl::acl_origin_user, '!=')
            ->where(Acl::schema_fields_MODULE, $installedModules, 'not in')
            ->select()
            ->fetchArray();

        if (empty($orphanRows)) {
            return;
        }

        $orphanSourceIds = array_values(array_filter(array_map(
            static fn(array $row): string => (string)($row[Acl::schema_fields_SOURCE_ID] ?? ''),
            $orphanRows
        )));

        if (empty($orphanSourceIds)) {
            return;
        }

        /** @var RoleAccess $roleAccess */
        $roleAccess = ObjectManager::getInstance(RoleAccess::class);
        $roleAccess->reset()
            ->where(RoleAccess::schema_fields_SOURCE_ID, $orphanSourceIds, 'in')
            ->delete()
            ->fetch();

        $this->acl->reset()
            ->where(Acl::schema_fields_SOURCE_ID, $orphanSourceIds, 'in')
            ->delete()
            ->fetch();
    }
}

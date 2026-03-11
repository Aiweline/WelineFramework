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
use Weline\Acl\Service\CollectedAclSourceIdsRegistry;
use Weline\Backend\Config\MenuXmlReader;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * 路由收集后执行 ACL 孤儿 diff：删除不在「收集到的菜单 ∪ 收集到的 ACL」中的记录（acl_origin != user）。
 */
class AfterRouteCollectionAclDiff implements ObserverInterface
{
    public function __construct(
        private Acl $acl
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $validMenuSourceIds = $this->getCollectedMenuSourceIds();
        $validAclSourceIds = CollectedAclSourceIdsRegistry::getAll();
        $validSourceIds = array_flip(array_merge($validMenuSourceIds, $validAclSourceIds));

        $allRows = $this->acl->reset()
            ->where(Acl::schema_fields_ACL_ORIGIN, Acl::acl_origin_user, '!=')
            ->select()
            ->fetchArray();

        $orphanSourceIds = [];
        foreach ($allRows as $row) {
            $sourceId = (string)($row[Acl::schema_fields_SOURCE_ID] ?? '');
            if ($sourceId !== '' && !isset($validSourceIds[$sourceId])) {
                $orphanSourceIds[] = $sourceId;
            }
        }

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

    /**
     * 从 menu.xml 收集到的菜单 source_id 列表
     *
     * @return string[]
     */
    private function getCollectedMenuSourceIds(): array
    {
        /** @var MenuXmlReader $menuReader */
        $menuReader = ObjectManager::getInstance(MenuXmlReader::class);
        $moduleMenus = $menuReader->read();
        $sources = [];
        foreach ($moduleMenus as $menus) {
            $data = $menus['data'] ?? [];
            foreach ($data as $menu) {
                $source = $menu['source'] ?? '';
                if ($source !== '') {
                    $sources[] = $source;
                }
            }
        }
        return $sources;
    }
}

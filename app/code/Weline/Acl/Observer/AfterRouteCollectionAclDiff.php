<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 */

namespace Weline\Acl\Observer;

use Weline\Acl\Service\AclOrphanCleanupService;
use Weline\Acl\Service\CollectedAclSourceIdsRegistry;
use Weline\Backend\Config\MenuXmlReader;
use Weline\Framework\App\Env;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

/**
 * 路由收集后执行 ACL 孤儿 diff：
 * 1) 删除不在「收集到的菜单 ∪ 收集到的 ACL」中的激活模块内记录；
 * 2) 删除不属于当前激活模块的记录。
 * 仅处理非用户创建的（acl_origin 为 NULL、空或 != 'user'），用户创建的不删除。
 */
class AfterRouteCollectionAclDiff implements ObserverInterface
{
    public function __construct(
        private MenuXmlReader $menuReader,
        private AclOrphanCleanupService $aclOrphanCleanupService
    ) {
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $validSourceIds = array_merge(
            $this->getCollectedMenuSourceIds(),
            CollectedAclSourceIdsRegistry::getAll()
        );
        $activeModules = array_keys(Env::getInstance()->getActiveModules());
        $this->aclOrphanCleanupService->cleanupByActiveModules($activeModules, $validSourceIds);
    }

    /**
     * 从 menu.xml 收集到的菜单 source_id 列表
     *
     * @return string[]
     */
    private function getCollectedMenuSourceIds(): array
    {
        $moduleMenus = $this->menuReader->read();
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

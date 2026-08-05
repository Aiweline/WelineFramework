<?php

declare(strict_types=1);

namespace Weline\Acl\Observer;

use Weline\Acl\Service\AclOrphanCleanupService;
use Weline\Acl\Service\CollectedAclSourceIdsRegistry;
use Weline\Acl\Service\MenuSourceIds;
use Weline\Acl\Service\Resource\LiveSourceSet;
use Weline\Framework\App\Env;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

/**
 * 路由收集后执行 ACL 孤儿 diff（D-10/D-11）：
 * live set = 菜单 ∪ ControllerAttributes registry ∪ Framework catalog LiveSourceSet
 * partial：仅清理 touched modules 内孤儿
 */
class AfterRouteCollectionAclDiff implements ObserverInterface
{
    public function __construct(
        private MenuSourceIds $menuSourceIds,
        private AclOrphanCleanupService $aclOrphanCleanupService
    ) {
    }

    public function execute(Event &$event): void
    {
        $validSourceIds = \array_values(\array_unique(\array_merge(
            $this->menuSourceIds->all(),
            CollectedAclSourceIdsRegistry::getAll(),
            LiveSourceSet::all(),
        )));
        $activeModules = \array_keys(Env::getInstance()->getActiveModules());
        $touched = $event->getData('touched_modules');
        if (!\is_array($touched) || $touched === []) {
            $touched = LiveSourceSet::touchedModules();
        }
        $this->aclOrphanCleanupService->cleanupByActiveModules(
            $activeModules,
            $validSourceIds,
            \is_array($touched) ? \array_values(\array_map('strval', $touched)) : null,
        );
    }
}

<?php
declare(strict_types=1);

namespace Weline\Acl\Observer;

use Weline\Acl\Service\CollectedAclSourceIdsRegistry;
use Weline\Acl\Service\Resource\LiveSourceSet;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

/**
 * 路由收集前清空 CollectedAclSourceIdsRegistry / LiveSourceSet。
 */
class ClearCollectedAclRegistry implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        CollectedAclSourceIdsRegistry::clear();
        LiveSourceSet::clear();
    }
}

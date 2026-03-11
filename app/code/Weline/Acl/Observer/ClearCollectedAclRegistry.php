<?php
declare(strict_types=1);

namespace Weline\Acl\Observer;

use Weline\Acl\Service\CollectedAclSourceIdsRegistry;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

/**
 * 路由收集前清空 CollectedAclSourceIdsRegistry，确保本次收集的 source_ids 准确。
 */
class ClearCollectedAclRegistry implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        CollectedAclSourceIdsRegistry::clear();
    }
}

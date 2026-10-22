<?php

declare(strict_types=1);

namespace Weline\Search\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

/** 升级时不重建搜索索引，交给每天闲时的定时任务。 */
final class SetupProviderIndexWarmupObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $printing = null;
        if (PHP_SAPI === 'cli') {
            try {
                /** @var Printing $printing */
                $printing = ObjectManager::getInstance(Printing::class);
            } catch (\Throwable) {
                $printing = null;
            }
        }

        $printing?->note(__('系统正在更新，搜索索引重建改到闲时再做。'));
    }
}

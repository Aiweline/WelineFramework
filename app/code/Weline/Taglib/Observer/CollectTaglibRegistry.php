<?php
declare(strict_types=1);

namespace Weline\Taglib\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Taglib\Console\Taglib\Collect;

class CollectTaglibRegistry implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $moduleNames = $event->getData('module_names');
        $skipTemplateCacheClear = (bool)$event->getData('skip_template_cache_clear');
        $moduleNames = is_array($moduleNames) ? array_values(array_filter(array_map('strval', $moduleNames))) : [];

        try {
            /** @var Collect $collect */
            $collect = ObjectManager::getInstance(Collect::class);
            $collectArgs = ['skip_template_cache_clear' => $skipTemplateCacheClear];
            if (!empty($moduleNames)) {
                $collect->execute(['module' => implode(',', $moduleNames)], $collectArgs);
            } else {
                $collect->execute([], $collectArgs);
            }
            $event->setData('result', [
                'success' => true,
            ]);
        } catch (\Throwable $throwable) {
            $event->setData('result', [
                'success' => false,
                'message' => $throwable->getMessage(),
            ]);
        }
    }
}

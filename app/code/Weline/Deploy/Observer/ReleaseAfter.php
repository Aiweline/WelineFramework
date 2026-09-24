<?php

declare(strict_types=1);

namespace Weline\Deploy\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\Deploy\DeployFpcInvalidation;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * 发布完成后：同步 theme_static_version；经 DeployFpcInvalidation bump deploy ns（禁拷贝静态）。
 */
class ReleaseAfter implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data          = $event->getData();
        $deployVersion = (string)$data->getData('deploy_version');

        if ($deployVersion !== '') {
            try {
                Env::getInstance()->setConfig('theme_static_version', $deployVersion);
                Env::getInstance()->setConfig('theme.static_version', $deployVersion);
            } catch (\Throwable) {
                // 静默
            }
        }

        try {
            /** @var DeployFpcInvalidation $invalidation */
            $invalidation = ObjectManager::getInstance(DeployFpcInvalidation::class);
            // stamp 已由 Orchestrator 写出；视为 stamp 变更 → bump（不 purge_fpc_all）
            $invalidation->afterUpgrade(true);
        } catch (\Throwable) {
            // 失效失败不得拖垮发布主路径；店面可能短暂粘滞至下次 bump
        }
    }
}

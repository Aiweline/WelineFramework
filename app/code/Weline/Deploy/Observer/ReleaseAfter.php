<?php

declare(strict_types=1);

namespace Weline\Deploy\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

/**
 * 发布完成后：同步 theme.static_version，使静态资源 URL 带上新版本号。
 */
class ReleaseAfter implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data          = $event->getData();
        $deployVersion = (string)$data->getData('deploy_version');

        if ($deployVersion !== '') {
            try {
                Env::getInstance()->setConfig('theme.static_version', $deployVersion);
            } catch (\Throwable) {
                // 静默
            }
        }
    }
}

<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Frontend\Controller;

use Weline\Backend\Model\Config as BackendConfig;
use Weline\FileManager\Helper\Image as ImageHelper;
use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Manager\ObjectManager;

class Maintenance extends FrontendController
{
    public function get()
    {
        $this->assignMaintenanceLogo();
        return $this->fetch();
    }

    private function assignMaintenanceLogo(): void
    {
        try {
            $backendConfig = ObjectManager::getInstance(BackendConfig::class);
            $logoDark = $backendConfig->getConfig('logo_dark', 'Weline_Backend') ?: '';
            $logoLight = $backendConfig->getConfig('logo_light', 'Weline_Backend') ?: '';
            $this->assign('maintenance_logo_dark', $logoDark !== '' ? ImageHelper::pathToMediaUrl($logoDark, 200, 200) : '');
            $this->assign('maintenance_logo_light', $logoLight !== '' ? ImageHelper::pathToMediaUrl($logoLight, 200, 200) : '');
        } catch (\Throwable $e) {
            $this->assign('maintenance_logo_dark', '');
            $this->assign('maintenance_logo_light', '');
        }
    }
}

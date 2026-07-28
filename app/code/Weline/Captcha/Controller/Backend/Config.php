<?php

declare(strict_types=1);

namespace Weline\Captcha\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;

#[Acl('Weline_Captcha::config', '人机验证配置', 'mdi mdi-shield-check', '系统配置')]
final class Config extends BackendController
{
    public function index(): string
    {
        return $this->redirect($this->request->getUrlBuilder()->getBackendUrl(
            'weline_systemconfig/backend/config',
            ['module' => 'Weline_Captcha', 'area' => 'backend']
        ));
    }
}

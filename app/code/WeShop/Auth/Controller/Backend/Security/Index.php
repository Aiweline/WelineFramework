<?php

declare(strict_types=1);

namespace WeShop\Auth\Controller\Backend\Security;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;

#[Acl('WeShop_Auth::security', '账号安全', 'mdi mdi-shield-account-outline', '管理当前后台账号的登录安全设置。', 'Weline_Backend::user_management')]
class Index extends BackendController
{
    public function index(): string
    {
        $this->assign('page_title', __('账号安全'));

        return $this->fetch('WeShop_Auth::templates/Backend/Security/index.phtml');
    }
}

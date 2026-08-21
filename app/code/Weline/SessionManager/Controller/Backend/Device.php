<?php

declare(strict_types=1);

namespace Weline\SessionManager\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;

#[Acl(
    'Weline_SessionManager::device_manage_self',
    '设备管理',
    'circle',
    '管理当前后台管理员自己的登录设备',
    'Weline_Backend::user_permission_group',
)]
final class Device extends BackendController
{
    public function getIndex(): string
    {
        $this->assign('device_manager_area', 'backend');
        return $this->fetch('index');
    }
}

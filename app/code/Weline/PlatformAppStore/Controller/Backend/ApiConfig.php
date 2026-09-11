<?php
declare(strict_types=1);

namespace Weline\PlatformAppStore\Controller\Backend;

use Weline\Framework\Acl\Acl;
use Weline\Framework\App\Controller\BackendController;

#[Acl('Weline_PlatformAppStore::api_config', 'API配置', 'mdi mdi-cog', '平台应用商店 API 配置说明', 'Weline_PlatformAppStore::platform')]
class ApiConfig extends BackendController
{
    #[Acl('Weline_PlatformAppStore::api_config_view', '查看API配置', 'settings', '查看平台 API 配置说明')]
    public function index(): string
    {
        $this->assign('api_base', '/rest/v1/platform');
        $this->assign('endpoints', [
            ['method' => 'POST', 'path' => '/module/list', 'title' => '模块列表'],
            ['method' => 'POST', 'path' => '/module/detail', 'title' => '模块详情'],
            ['method' => 'POST', 'path' => '/license/activate', 'title' => '激活许可证'],
        ]);
        $this->assign('page_title', __('API配置'));
        $this->assign('title', __('API配置'));

        return $this->fetch();
    }
}

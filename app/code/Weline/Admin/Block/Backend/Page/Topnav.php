<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Admin\Block\Backend\Page;

use Weline\Acl\Model\Role;
use Weline\Acl\Service\ResourceTreeServiceInterface;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;
use Weline\Framework\Manager\ObjectManager;

class Topnav extends \Weline\Framework\View\Block
{
    public string $_template = 'Weline_Admin::backend/public/topnav.phtml';
    private \Weline\Backend\Block\ThemeConfig $themeConfig;
    private ?ResourceTreeServiceInterface $resourceTreeService = null;

    public function __construct(
        \Weline\Backend\Block\ThemeConfig $themeConfig,
        array                             $data = []
    ) {
        $this->themeConfig = $themeConfig;
        parent::__construct($data);
    }

    /**
     * 获取资源树服务
     */
    private function getResourceTreeService(): ResourceTreeServiceInterface
    {
        if ($this->resourceTreeService === null) {
            $this->resourceTreeService = ObjectManager::getInstance(ResourceTreeServiceInterface::class);
        }
        return $this->resourceTreeService;
    }

    public function __init()
    {
        parent::__init();
        # 检测是否为水平布局，水平布局时显示顶部菜单
        $currentLayouts = $this->themeConfig->getOriginThemeConfig('layouts') ?? [];
        $dataLayout = $currentLayouts['data-layout'] ?? '';
        if ($dataLayout === 'horizontal') {
            $this->processMenu();
        }
    }

    public function render():string
    {
        # 检测是否为水平布局，水平布局时显示顶部菜单
        $currentLayouts = $this->themeConfig->getOriginThemeConfig('layouts') ?? [];
        $dataLayout = $currentLayouts['data-layout'] ?? '';
        if ($dataLayout === 'horizontal') {
            return parent::render();
        }
        return '';
    }

    public function processMenu()
    {
        // 获取当前登录用户和角色
        /**@var AuthenticatedSessionInterface $session */
        $session = SessionFactory::getInstance()->createBackendSession();
        /**@var \Weline\Backend\Model\BackendUser $user */
        $user = $session->getLoginUser();
        if ($user && $user->getId()) {
            // WLS 兼容：按当前用户的 role_id 重新加载 Role，避免菜单权限不全
            $roleId = (int) ($user->getRole()->getRoleId() ?: 0);
            if ($roleId > 0) {
                $role = ObjectManager::getInstance(Role::class, [], false)->load($roleId);
                $menus = $role->getId() ? $this->getResourceTreeService()->getBackendMenuTree($role) : [];
            } else {
                $menus = [];
            }
        } else {
            $menus = [];
        }
        // 确保 menus 始终是数组，避免 foreach 循环错误
        $this->assign('menus', is_array($menus) ? $menus : []);
    }
}

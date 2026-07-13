<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Admin\Block\Backend\Page;

use Weline\Acl\Api\Resource\MenuResourceServiceInterface;
use Weline\Backend\Api\Auth\BackendUserContextProviderInterface;
use Weline\Backend\Api\View\BackendThemeConfigInterface;
use Weline\Framework\Manager\ObjectManager;

class Topnav extends \Weline\Framework\View\Block
{
    public string $_template = 'Weline_Admin::backend/public/topnav.phtml';
    private BackendThemeConfigInterface $themeConfig;
    private ?MenuResourceServiceInterface $menuResourceService = null;
    private ?BackendUserContextProviderInterface $userContextProvider = null;

    public function __construct(
        BackendThemeConfigInterface $themeConfig,
        array                             $data = []
    ) {
        $this->themeConfig = $themeConfig;
        parent::__construct($data);
    }

    /**
     * 获取资源树服务
     */
    private function getMenuResourceService(): MenuResourceServiceInterface
    {
        if ($this->menuResourceService === null) {
            $this->menuResourceService = ObjectManager::getInstance(MenuResourceServiceInterface::class);
        }
        return $this->menuResourceService;
    }

    private function getUserContextProvider(): BackendUserContextProviderInterface
    {
        if ($this->userContextProvider === null) {
            $this->userContextProvider = ObjectManager::getInstance(BackendUserContextProviderInterface::class);
        }
        return $this->userContextProvider;
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
        $user = $this->getUserContextProvider()->current();
        $roleId = $user?->getRoleId() ?? 0;
        $menus = $roleId > 0
            ? $this->getMenuResourceService()->getBackendMenuTreeByRoleId($roleId)
            : [];
        // 确保 menus 始终是数组，避免 foreach 循环错误
        $this->assign('menus', is_array($menus) ? $menus : []);
    }
}

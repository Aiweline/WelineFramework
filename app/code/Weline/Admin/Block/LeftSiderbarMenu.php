<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Admin\Block;

use Weline\Acl\Service\ResourceTreeServiceInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Session\Auth\AuthenticatedSessionInterface;
use Weline\Framework\Session\SessionFactory;

class LeftSiderbarMenu extends \Weline\Framework\View\Block
{
    private ?ResourceTreeServiceInterface $resourceTreeService = null;

    public function getMenuTree()
    {
        if ($this->resourceTreeService === null) {
            $this->resourceTreeService = ObjectManager::getInstance(ResourceTreeServiceInterface::class);
        }
        
        // 获取当前登录用户和角色
        /** @var AuthenticatedSessionInterface $session */
        $session = SessionFactory::getInstance()->createBackendSession();
        /** @var \Weline\Backend\Model\BackendUser $user */
        $user = $session->getLoginUser();
        
        if (!$user || !$user->getId()) {
            return [];
        }
        
        $role = $user->getRoleModel();
        if (!$role || !$role->getId()) {
            return [];
        }
        
        return $this->resourceTreeService->getBackendMenuTree($role);
    }
}

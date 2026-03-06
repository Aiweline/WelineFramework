<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2023/1/19 22:23:44
 */

namespace Weline\Admin\Observer;

use Weline\Framework\Session\SessionFactory;
use Weline\Framework\Event\Event;

class RoleChecker implements \Weline\Framework\Event\ObserverInterface
{
    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        // WLS 兼容：每次执行时获取当前请求的 BackendSession，避免 Observer 单例持有旧请求的 session
        $session = SessionFactory::getInstance()->createBackendSession();
        $user = $session->getUser();
        
        if ($user === null || !method_exists($user, 'getRole')) {
            return;
        }
        $userRole = $user->getRole();
        if ($userRole === null) {
            return;
        }
        /**@var \Weline\Acl\Model\Role $role */
        $role = $event->getData('data');
        if ($role !== null) {
            $role->setData($userRole->getData());
        }
    }
}
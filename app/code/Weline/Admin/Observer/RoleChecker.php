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

use Weline\Admin\Session\AdminSession;
use Weline\Framework\Event\Event;

class RoleChecker implements \Weline\Framework\Event\ObserverInterface
{
    /**
     * @var \Weline\Admin\Session\AdminSession
     */
    private AdminSession $session;

    function __construct(
        AdminSession $session
    )
    {
        $this->session = $session;
    }

    /**
     * @inheritDoc
     */
    public function execute(Event $event)
    {
        /**@var \Weline\Acl\Model\Role $role */
        $role = $event->getData('data');
        $role->setData($this->session->getLoginUser()->getRole()->getData());
    }
}
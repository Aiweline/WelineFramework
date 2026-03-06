<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Backend\Observer;

use Weline\Backend\Setup\EnsureAdmin;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * 系统升级完成后，确保默认管理员（admin）存在且拥有 role_id=1。
 * 避免升级后登录提示「用户没有分配角色」。
 */
class SetupUpgradeAfter implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        /** @var EnsureAdmin $ensureAdmin */
        $ensureAdmin = ObjectManager::getInstance(EnsureAdmin::class);
        $ensureAdmin->ensure();
    }
}

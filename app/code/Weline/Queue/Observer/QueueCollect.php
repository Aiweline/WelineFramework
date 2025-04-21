<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Administrator
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：11/7/2023 09:36:16
 */

namespace Weline\Queue\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\Event\Event;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Module\Config\ModuleFileReader;
use Weline\Framework\Module\Model\Module;
use Weline\Queue\Helper\Helper;
use Weline\Queue\Model\Queue\Type;
use Weline\Queue\QueueInterface;

class QueueCollect implements \Weline\Framework\Event\ObserverInterface
{
    private Helper $helper;

    function __construct(Helper $helper)
    {
        $this->helper = $helper;
    }

    /**
     * @inheritDoc
     * @throws \Exception
     */
    public function execute(Event &$event)
    {
        $this->helper::collect();
    }
}

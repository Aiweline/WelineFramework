<?php

declare(strict_types=1);

namespace Weline\DeveloperWorkspace\Console\Doc;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Event\EventsManager;

class Import extends CommandAbstract
{
    public const dir = 'Console\\Doc';

    public function execute(array $args = [], array $data = [])
    {
        /** @var EventsManager $eventsManager */
        $eventsManager = ObjectManager::getInstance(EventsManager::class);
        // 触发模块升级事件，让 observer 来处理文档导入
        $eventsManager->dispatch('Framework_Module::module_upgrade');
        $this->printer->success('文档导入任务已触发');
    }

    public function tip(): string
    {
        return '触发模块文档导入任务';
    }
}



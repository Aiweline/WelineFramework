<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/10/27 20:35:14
 */

namespace Weline\Cron\Schedule;

use Weline\Framework\Manager\ObjectManager;

class Schedule implements ScheduleInterface
{
    public function create(string $name): array
    {
        return $this->getScheduler()->create($name);
    }

    public function run(string $name): array
    {
        return $this->getScheduler()->run($name);
    }

    public function remove(string $name): array
    {
        return $this->getScheduler()->remove($name);
    }

    public function getScheduler(): ScheduleInterface
    {
        $cron_class = IS_WIN ? 'Windows\Schtasks' : 'Linux\Crontab';
        return ObjectManager::getInstance("Weline\Cron\Schedule\\$cron_class");
    }

    public function exist(string $name): bool
    {
        return $this->getScheduler()->exist("schtasks /query /tn $name");
    }
}

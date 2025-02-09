<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/10/26 21:34:00
 */

namespace Weline\Cron\Console\Cron\Task;

use Weline\Backend\Model\Config;
use Weline\Cron\Console\Cron\BaseCommand;
use Weline\Cron\Model\CronTask;
use Weline\Cron\Schedule\Schedule;
use Weline\Framework\Output\Cli\Printing;

class Listing extends BaseCommand
{
    private CronTask $cronTask;

    public function __construct(Config $config, Printing $printing, Schedule $schedule, CronTask $cronTask)
    {
        parent::__construct($config, $printing, $schedule);
        $this->cronTask = $cronTask;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $tasks     = $this->cronTask->select()->fetch()->getItems();
        $taskTotal = count($tasks);
        $this->printing->note('定时任务总数：' . $taskTotal);
        $this->printing->setup(mb_str_pad(__('代码'), 35, ' ', STR_PAD_RIGHT, 'UTF-8') . mb_str_pad('名称', 55, ' ', STR_PAD_RIGHT, 'UTF-8') .'说明');
        foreach ($tasks as $key => $task) {
            $task_name = $task->getData(CronTask::fields_NAME);
            $task_code = $task->getData(CronTask::fields_EXECUTE_NAME);
            $task_tip  = $task->getData(CronTask::fields_TIP);
            $task_tips = explode(PHP_EOL, $task_tip);
            foreach ($task_tips as $k=> &$taskTip) {
                if($k > 0) {
                    $taskTip = mb_str_pad('', 90, ' ', STR_PAD_RIGHT, 'UTF-8').trim($taskTip);
                }
            }
            $task_tip = implode(PHP_EOL, $task_tips);
            $this->printing->note($this->printing->colorize(mb_str_pad($task_code, 35, ' ', STR_PAD_RIGHT, 'UTF-8'), 'Green') . mb_str_pad($this->printing->colorize($task_name, 'Blue'), 55, ' ', STR_PAD_RIGHT, 'UTF-8') . $task_tip);
        }
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '查看系统定时任务。';
    }
}
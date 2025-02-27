<?php
declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 作者：Admin
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 * 日期：2022/10/30 01:26:05
 */

namespace Weline\Cron\Console\Cron\Task;

use Weline\Cron\CronTaskInterface;
use Weline\Cron\Model\CronTask;
use Weline\Framework\App\Debug;
use Weline\Framework\App\Env;
use Weline\Framework\Console\CommandInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\System\File\Scan;

class Collect implements CommandInterface
{
    /**
     * @var \Weline\Framework\System\File\Scan
     */
    private Scan $scan;
    /**
     * @var \Weline\Cron\Model\CronTask
     */
    private CronTask $cronTask;
    /**
     * @var \Weline\Framework\Output\Cli\Printing
     */
    private Printing $printing;

    function __construct(
        Scan     $scan,
        CronTask $cronTask,
        Printing $printing
    )
    {
        $this->scan = $scan;
        $this->cronTask = $cronTask;
        $this->printing = $printing;
    }

    public function execute(array $args = [], array $data = [])
    {
        $modules = Env::getInstance()->getActiveModules();
        foreach ($modules as $module) {
            if (is_dir($module['base_path'] . 'Cron')) {
                $tasks = [];
                $this->scan->globFile(
                    $module['base_path'] . 'Cron' . DS . '*',
                    $tasks, '.php',
                    $module['base_path'],
                    $module['namespace_path'] . '\\',
                    true,
                    true
                );
                foreach ($tasks as $task) {
                    /**@var \Weline\Cron\CronTaskInterface $taskObject */
                    $taskObject = ObjectManager::getInstance($task);
                    if ($taskObject instanceof CronTaskInterface) {
                        $this->cronTask->clearData()
                            ->setData(CronTask::fields_NAME, $taskObject->name())
                            ->setData(CronTask::fields_EXECUTE_NAME, $taskObject->execute_name(), true)
                            ->setData(CronTask::fields_CLASS, $taskObject::class)
                            ->setData(CronTask::fields_TIP, $taskObject->tip())
                            ->setData(CronTask::fields_CRON_TIME, $taskObject->cron_time())
                            ->setData(CronTask::fields_BLOCK_UNLOCK_TIMEOUT, $taskObject->unlock_timeout())
                            ->setData(CronTask::fields_MODULE, $module['name'])
                            ->save();
                    }
                }
            }
        }
        $this->printing->success(__('调度任务收集完成！'));
    }

    public function tip(): string
    {
        return __('收集注册调度任务');
    }
}
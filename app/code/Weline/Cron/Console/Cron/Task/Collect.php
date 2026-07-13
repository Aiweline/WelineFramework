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

use Weline\Framework\Cron\CronTaskInterface as FrameworkCronTaskInterface;
use Weline\Cron\Model\CronTask;
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
        /** @var list<string> 物理文件为唯一来源：仅保留此次扫描到的任务类 */
        $collectedClasses = [];
        $added = 0;

        foreach ($modules as $module) {
            if (!is_dir($module['base_path'] . 'Cron')) {
                continue;
            }
            $tasks = [];
            $this->scan->globFile(
                $module['base_path'] . 'Cron' . DS . '*',
                $tasks,
                '.php',
                $module['base_path'],
                $module['namespace_path'] . '\\',
                true,
                true
            );
            foreach ($tasks as $task) {
                try {
                    if (!class_exists($task)) {
                        continue;
                    }
                    $reflection = new \ReflectionClass($task);
                    if (
                        !$reflection->isInstantiable()
                        || !$reflection->implementsInterface(FrameworkCronTaskInterface::class)
                    ) {
                        continue;
                    }
                    /** @var FrameworkCronTaskInterface $taskObject */
                    $taskObject = ObjectManager::getInstance($task);
                } catch (\Throwable) {
                    continue;
                }
                if (!$taskObject instanceof FrameworkCronTaskInterface) {
                    continue;
                }
                // 先标记为已收集，避免 save() 异常或后续逻辑导致本类未入列表，removeStaleTaskRecords 误删本条
                $collectedClasses[] = $taskObject::class;
                $existing = $this->cronTask->clearQuery()
                    ->where(CronTask::schema_fields_EXECUTE_NAME, $taskObject->execute_name())
                    ->find()
                    ->fetch();
                $existingId = $existing->getId();
                $model = $this->cronTask->clearData()
                    ->setData(CronTask::schema_fields_NAME, $taskObject->name())
                    ->setData(CronTask::schema_fields_EXECUTE_NAME, $taskObject->execute_name(), true)
                    ->setData(CronTask::schema_fields_CLASS, $taskObject::class)
                    ->setData(CronTask::schema_fields_TIP, $taskObject->tip())
                    ->setData(CronTask::schema_fields_CRON_TIME, $taskObject->cron_time())
                    ->setData(CronTask::schema_fields_BLOCK_UNLOCK_TIMEOUT, $taskObject->unlock_timeout())
                    ->setData(CronTask::schema_fields_MODULE, $module['name']);
                if ($existingId) {
                    $model->setData(CronTask::schema_fields_ID, $existingId);
                } else {
                    $added++;
                }
                $model->save();
            }
        }

        // Diff 删除：表中 class 不在本次收集列表中的记录（物理文件已删除的任务）
        $deleted = $this->removeStaleTaskRecords($collectedClasses);
        if ($added > 0) {
            $this->printing->note((string) __('新增 %{1} 条调度任务', [$added]));
        }
        if ($deleted > 0) {
            $this->printing->warning((string) __('已移除 %{1} 条已无物理文件的任务记录', [$deleted]));
        }
        $this->printing->success(__('调度任务收集完成！'));
    }

    /**
     * 删除表中 class 不在收集列表中的记录（以物理文件为唯一来源）
     *
     * @param list<string> $collectedClasses 本次扫描到的任务类名列表
     * @return int 删除条数
     */
    private function removeStaleTaskRecords(array $collectedClasses): int
    {
        $model = clone $this->cronTask;
        $rows = $model->clearQuery()->select()->fetch()->getItems();
        if ($rows === []) {
            return 0;
        }
        $allowed = [];
        foreach ($collectedClasses as $c) {
            $allowed[self::normalizeClass($c)] = true;
        }
        $deleted = 0;
        foreach ($rows as $row) {
            $class = self::normalizeClass((string) ($row->getData(CronTask::schema_fields_CLASS) ?? ''));
            if ($class !== '' && !isset($allowed[$class])) {
                $row->delete()->fetch();
                $deleted++;
            }
        }
        return $deleted;
    }

    private static function normalizeClass(string $class): string
    {
        $class = trim(str_replace('/', '\\', $class));
        return $class;
    }

    public function tip(): string
    {
        return __('收集注册调度任务');
    }

    public function help(): array|string
    {
        // 基于tip的默认help实现
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            '',
            $this->tip(),
            [
                '-h, --help' => '显示帮助信息',
            ],
            [],
            []
        );
    }
}

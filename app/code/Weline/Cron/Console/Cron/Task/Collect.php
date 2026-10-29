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
                // 稳定身份优先 class（物理文件路径，不参与 i18n）：name() 的返回值经 __() 按运行期语言翻译，
                // 同一进程内还可能被并发 i18n 字典收集清缓存而翻转，用译文做 WHERE 会让 UPDATE 匹配 0 行。
                // name 只作普通数据列（不放进 unique_data）：一旦进了唯一键就会被 checkUpdateOrInsert 从 SET 中剔除，
                // 改名永远落不了库（见 AbstractModel::checkUpdateOrInsert 的「出去条件中的唯一值」）。
                $existing = $this->findExistingTaskRow($taskObject->name(), $taskObject::class);
                $existingId = $existing['id'] ?? null;
                $nameValue = (string) $taskObject->name();
                if ($existingId && $nameValue !== (string) ($existing['name'] ?? '')) {
                    // 改名要避开 UNIQUE_TASK_NAME：目标名已被别的任务占用时保留库存值，宁可不同步也不写坏唯一索引
                    if ($this->isTaskNameTakenByOtherRow($nameValue, $existingId)) {
                        $nameValue = (string) ($existing['name'] ?? $nameValue);
                    }
                }
                $identityValues = [
                    CronTask::schema_fields_NAME => $nameValue,
                    CronTask::schema_fields_EXECUTE_NAME => $taskObject->execute_name(),
                    CronTask::schema_fields_CLASS => $taskObject::class,
                    CronTask::schema_fields_TIP => $taskObject->tip(),
                    CronTask::schema_fields_CRON_TIME => $taskObject->cron_time(),
                    CronTask::schema_fields_BLOCK_UNLOCK_TIMEOUT => $taskObject->unlock_timeout(),
                    CronTask::schema_fields_MODULE => $module['name'],
                ];
                if ($existingId) {
                    // 已有行：值与库内完全一致时不写，避免每分钟一次全表零差异 UPDATE 打爆 WAL 与锁
                    if ($this->sameAsStored($existingId, $identityValues)) {
                        continue;
                    }
                } else {
                    $added++;
                }
                $model = $this->cronTask->clearData();
                foreach ($identityValues as $field => $value) {
                    $model->setData($field, $value);
                }
                if ($existingId) {
                    // 用主键作 UPDATE 的唯一键：不依赖任何可翻译字段，译文怎么翻都不会再匹配 0 行
                    $model->setData(CronTask::schema_fields_ID, $existingId, true);
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
     * 按稳定身份查找已有任务行：class（物理文件，不参与 i18n）优先，name 兜底。
     * execute_name 可走 i18n，不能作唯一查重键。
     *
     * 返回库中存量的 id 与 name：调用方必须用「库存 name」而不是 name() 的译文做唯一键，
     * 否则 UPDATE 的 WHERE 匹配不到本行（见类注释）。
     *
     * @return array{id:int|string,name:string}|null
     */
    private function findExistingTaskRow(string $name, string $class): ?array
    {
        // class 是物理命名空间，不经过 __() 翻译，也不会被并发 i18n 字典收集改写，最先用它定位
        $normalizedClass = self::normalizeClass($class);
        if ($normalizedClass !== '') {
            $byClass = $this->cronTask->clearData()
                ->where(CronTask::schema_fields_CLASS, $normalizedClass)
                ->find()
                ->fetch();
            $id = $byClass->getId();
            if ($id) {
                // 库中 name 可能是历史存量（英文/旧译文），必须取库存值作唯一键
                return ['id' => $id, 'name' => (string) ($byClass->getData(CronTask::schema_fields_NAME) ?? $name)];
            }
        }
        // 兜底按 name：仅用于老库中 class 缺失或类被改名的行
        $byName = $this->cronTask->clearData()
            ->where(CronTask::schema_fields_NAME, $name)
            ->find()
            ->fetch();
        $id = $byName->getId();
        if ($id) {
            return ['id' => $id, 'name' => (string) ($byName->getData(CronTask::schema_fields_NAME) ?? $name)];
        }
        return null;
    }

    /**
     * 目标任务名是否已被「其它行」占用（UNIQUE_TASK_NAME）。
     * 用于改名前的冲突保护：宁可保留库存名，也不写坏唯一索引。
     *
     * @param int|string $selfId 当前行主键
     */
    private function isTaskNameTakenByOtherRow(string $name, int|string $selfId): bool
    {
        if ($name === '') {
            return false;
        }
        $row = $this->cronTask->clearData()
            ->where(CronTask::schema_fields_NAME, $name)
            ->find()
            ->fetch();
        $id = $row->getId();
        if (!$id) {
            return false;
        }
        return (string) $id !== (string) $selfId;
    }

    /**
     * 已有行的字段值与库中现值是否完全一致；一致则跳过 save()，避免零差异 UPDATE。
     *
     * @param int|string $id
     * @param array<string,mixed> $values
     */
    private function sameAsStored(int|string $id, array $values): bool
    {
        $stored = $this->cronTask->clearData()
            ->where(CronTask::schema_fields_ID, $id)
            ->find()
            ->fetch();
        if (!$stored->getId()) {
            // 查不到就保守地认为需要写入，让 save() 走原有 upsert 逻辑
            return false;
        }
        foreach ($values as $field => $value) {
            $storedValue = $stored->getData($field);
            // 库里可能是 null / 数字，统一按字符串比较，避免类型差异造成误判为“有变化”
            if ((string) ($storedValue ?? '') !== (string) ($value ?? '')) {
                return false;
            }
        }
        return true;
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

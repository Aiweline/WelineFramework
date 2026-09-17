<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Indexer\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Event\Event;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Module\Config\ModuleFileReader;
use Weline\Framework\Module\Model\Module;
use Weline\Framework\Php\FiberTaskBatch;
use Weline\Indexer\Model\Indexer;

class ReindexCollector implements \Weline\Framework\Event\ObserverInterface
{
    private ModuleFileReader $moduleFileReader;
    private Indexer $indexer;

    public function __construct(
        ModuleFileReader $moduleFileReader,
        Indexer          $indexer
    )
    {
        $this->moduleFileReader = $moduleFileReader;
        $this->indexer = $indexer;
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event): void
    {
        $modules = Env::getInstance()->getActiveModules();
        $batch = new FiberTaskBatch(null, true, 'WELINE_INDEXER_FIBER_CONCURRENCY');
        $batch->mapModules(
            $modules,
            function (string $moduleName, mixed $moduleData): array {
                $module = new Module(\is_array($moduleData) ? $moduleData : []);
                $found = [];
                $models = $this->moduleFileReader->readClass($module, 'Model');
                foreach ($models as $modelClass) {
                    if (!class_exists($modelClass)) {
                        continue;
                    }

                    $reflection = new \ReflectionClass($modelClass);
                    if ($reflection->isAbstract()
                        || $reflection->isTrait()
                        || $reflection->isInterface()
                        || !$reflection->isSubclassOf(AbstractModel::class)
                    ) {
                        continue;
                    }

                    $indexer = (string)$reflection->getConstant('indexer');
                    if ($indexer === '' || ObjectManager::isStaticClass($modelClass)) {
                        continue;
                    }

                    $model = ObjectManager::getInstance($modelClass);
                    if (!$model instanceof AbstractModel) {
                        continue;
                    }

                    $found[] = [
                        'indexer' => $indexer,
                        'module' => $module->getName(),
                        'model' => $model::class,
                        'table' => $model->getTable(),
                    ];
                }

                return $found;
            },
            function (string $phase, array $ctx): void {
                if ($phase !== 'task' || !($ctx['ok'] ?? false)) {
                    return;
                }
                $rows = $ctx['result'] ?? null;
                if (!\is_array($rows)) {
                    return;
                }
                foreach ($rows as $row) {
                    if (!\is_array($row)) {
                        continue;
                    }
                    $indexerName = (string)($row['indexer'] ?? '');
                    $modelClass = (string)($row['model'] ?? '');
                    if ($indexerName === '' || $modelClass === '') {
                        continue;
                    }
                    $hasIndexer = $this->indexer
                        ->reset()
                        ->clearData()
                        ->where([[$this->indexer::schema_fields_NAME, $indexerName], [$this->indexer::schema_fields_MODEL, $modelClass]])
                        ->find()
                        ->fetch();
                    if (!$hasIndexer->getId()) {
                        $this->indexer->setName($indexerName);
                        $this->indexer->setModuleName((string)($row['module'] ?? ''));
                        $this->indexer->setModuleModel($modelClass);
                        $this->indexer->setModuleTable((string)($row['table'] ?? ''));
                        $this->indexer->save();
                    }
                }
            },
            [
                'env' => 'WELINE_INDEXER_FIBER_CONCURRENCY',
                'fail_fast' => true,
                'label' => 'indexer-collect',
                'keep_results' => false,
            ]
        );
        unset($batch, $modules);
    }
}

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
use Weline\Indexer\Model\Indexer;

class ReindexCollector implements \Weline\Framework\Event\ObserverInterface
{
    private ModuleFileReader $moduleFileReader;
    private Indexer $indexer;

    /**
     *
     * @param ModuleFileReader $moduleFileReader
     * @param Indexer          $indexer
     */
    public function __construct(
        ModuleFileReader $moduleFileReader,
        Indexer          $indexer
    ) {
        $this->moduleFileReader = $moduleFileReader;
        $this->indexer          = $indexer;
    }

    /**
     * @inheritDoc
     */
    public function execute(Event $event)
    {
        $modules = Env::getInstance()->getActiveModules();
        foreach ($modules as $module) {
            $module = new Module($module);
            $models = $this->moduleFileReader->readClass($module, 'Model');
            foreach ($models as $model) {
                if (class_exists($model)) {
                    $model = ObjectManager::getInstance($model);
                    if ($model instanceof AbstractModel && $indexer = $model::indexer) {
                        # 检测是否有indexer
                        $hasIndexer = $this->indexer
                            ->reset()
                            ->clearData()
                            ->where([[$this->indexer::fields_NAME, $indexer], [$this->indexer::fields_MODEL, $model::class]])
                            ->find()
                            ->fetch();
                        if (!$hasIndexer->getId()) {
                            # 如果没有indexer，则创建
                            $this->indexer->setName($indexer);
                            $this->indexer->setModuleName($module->getName());
                            $this->indexer->setModuleModel($model::class);
                            $this->indexer->setModuleTable($model->getTable());
                            $this->indexer->save();
                        }
                    }
                }
            }
        }
    }
}

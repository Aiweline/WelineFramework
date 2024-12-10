<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Database\Console\Index;

use Weline\Framework\App\Env;
use Weline\Framework\Database\AbstractModel;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\EventsManager;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Module\Config\ModuleFileReader;
use Weline\Framework\Module\Model\Module;
use Weline\Framework\Output\Cli\Printing;

class Listing implements \Weline\Framework\Console\CommandInterface
{
    private ModuleFileReader $moduleFileReader;
    private Printing $printing;

    public function __construct(
        ModuleFileReader $moduleFileReader,
        Printing $printing
    ) {
        $this->moduleFileReader = $moduleFileReader;
        $this->printing = $printing;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        /**@var EventsManager $eventManager */
        $eventManager = ObjectManager::getInstance(EventsManager::class);
        $params =  new DataObject(['args' => $args,'break' => false]);
        $eventManager->dispatch('Framework_Database::indexer_listing', ['data'=>$params]);
        if($params->getData('break')) {
            return;
        }
        # 框架原版索引任务
        $indexers = [];
        $modules = Env::getInstance()->getActiveModules();
        foreach ($modules as $module) {
            $module = new Module($module);
            $models = $this->moduleFileReader->readClass($module, 'Model');
            foreach ($models as $model) {
                if (class_exists($model)) {
                    $model = ObjectManager::getInstance($model);
                    if ($model instanceof AbstractModel && $indexer = $model::indexer) {
                        $indexers[$indexer][] = $model;
                    }
                }
            }
        }
        /**@var Indexer $indexer */
        foreach ($indexers as $indexer => $indexItems) {
            $msg = str_pad($this->printing->colorize($indexer, $this->printing::SUCCESS), 35, ' ', STR_PAD_RIGHT).PHP_EOL;
            foreach ($indexItems as $indexItem) {
                $msg .= $this->printing->colorize($indexItem->getTable(), $this->printing::NOTE).PHP_EOL;
            }
            $this->printing->printing($msg);
        }
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('索引器列表');
    }
}

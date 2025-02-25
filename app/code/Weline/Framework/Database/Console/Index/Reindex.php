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

class Reindex implements \Weline\Framework\Console\CommandInterface
{
    private ModuleFileReader $moduleFileReader;

    public function __construct(
        ModuleFileReader $moduleFileReader,
        private Printing $printing
    )
    {
        $this->moduleFileReader = $moduleFileReader;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        /**@var EventsManager $eventManager */
        $eventManager = ObjectManager::getInstance(EventsManager::class);
        $params = new DataObject(['args' => $args, 'break' => false]);
        $eventManager->dispatch('Framework_Database::indexer', $params);
        if ($params->getData('break')) {
            return;
        }
        # 继续框架原版的索引重建
        array_shift($args);
        unset($args['command']);
        $args_indexers = $args;

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
        foreach ($indexers as $indexer => $indexerItems) {
            # 有参数
            if ($args_indexers) {
                if (in_array($indexer, $args_indexers)) {
                    $this->printing->note("开始重建索引：{$indexer} ");
                    foreach ($indexerItems as $indexerItem) {
                        $this->printing->note("索引组模型：" . $indexerItem::class);
                        $indexerItem->reindex();
                        $this->printing->success("索引重建完成：" . $indexerItem::class);
                    }
                }
            } else {
                $this->printing->note("开始重建索引：{$indexer} ");
                foreach ($indexerItems as $indexerItem) {
                    $this->printing->note("索引组模型：" . $indexerItem::class);
                    $indexerItem->reindex();
                    $this->printing->success("索引重建完成：" . $indexerItem::class);
                }
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '重建数据库表索引。示例：index:reindex weline_indexer （其中weline_indexer是模型索引器名，可以多个Model使用同一个索引器）';
    }
}

<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Indexer\Observer;

use Weline\Framework\Database\AbstractModel;
use Weline\Framework\Event\Event;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Indexer\Model\Indexer;

class Reindex implements \Weline\Framework\Event\ObserverInterface
{
    private \Weline\Indexer\Model\Indexer $indexer;
    private Printing $printing;

    public function __construct(
        \Weline\Indexer\Model\Indexer $indexer,
        Printing                      $printing
    )
    {
        $this->indexer = $indexer;
        $this->printing = $printing;
    }

    /**
     * @inheritDoc
     */
    public function execute(Event &$event)
    {
        $data = $event->getData('data');
        $args = $data->getData('args');
        $break = $data->getData('break');
        # 检测是否自定义索引重建
        array_shift($args);
        unset($args['command']);
        $args_indexers = $args;
        if ($args_indexers) {
            # 查找自定义索引是否在数据库中
            foreach ($args_indexers as $args_indexer) {
                $this->printing->note(__("开始重建指定索引：%1", $args_indexer));
                $indexers = $this->indexer->where('name', $args_indexer)->select()->fetch()->getItems();
                if (!$indexers) {
                    $this->printing->error(__('索引器 %1 找不到', $args_indexer));
                    continue;
                }
                foreach ($indexers as $indexer) {
                    if (class_exists($indexer->getModuleModel())) {
                        /**@var AbstractModel $model */
                        $model = ObjectManager::getInstance($indexer->getModuleModel());
                        $this->printing->note(__("开始重建索引：%1", $indexer['name']));
                        $this->printing->note(__("索引模型：%1", $model::class));
                        $model->reindex($model->getTable());
                        $this->printing->success(__("索引重建完成：%1", $indexer['name']));
                    } else {
                        $this->printing->error(__('索引模型不存在'));
                        return;
                    }
                }
            }
        } else {
            # 检索Model模型
            $indexers = $this->indexer->select()->fetch()->getItems();
            $indexersItems = [];
            foreach ($indexers as $indexer) {
                $indexersItems[$indexer->getName()][] = $indexer;
            }
            /**@var Indexer $indexer */
            foreach ($indexersItems as $indexer => $indexerItems) {
                $this->printing->note(__("开始重建索引：%1", $indexer));
                foreach ($indexerItems as $indexerItem) {
                    if (class_exists($indexerItem->getModuleModel())) {
                        /**@var AbstractModel $model */
                        $model = ObjectManager::getInstance($indexerItem->getModuleModel());
                        $model->reindex($model->getTable());
                        $this->printing->warning(__("重建索引：%1", $model->getTable()));
                        $this->printing->warning(__("索引模型：%1", $model::class));
                    } else {
                        $this->printing->error(__('索引模型不存在'));
                        return;
                    }
                }
                $this->printing->success(__("索引重建完成：%1", $indexer));
            }
        }

        # 所有索引重建完成
        $this->printing->note(__('所有索引重建完成'));
        $break = true;
        $data->setData('break', $break);
    }
}

<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Indexer\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Indexer\Model\Indexer;

class ReindexListing implements \Weline\Framework\Event\ObserverInterface
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
        unset($args['command']);
        # 检测是否自定义索引重建
        if ($args) {
            array_shift($args);
            if ($args) {
                $this->indexer->where('name', $args, 'in');
            }
        }

        $indexers = $this->indexer->select()->fetch()->getItems();
        $indexersItems = [];
        foreach ($indexers as $indexer) {
            $indexersItems[$indexer->getName()][] = $indexer->getData('module_table');
        }
        /**@var \Weline\Framework\Database\Model\Indexer $indexer */
        foreach ($indexersItems as $indexer => $indexerItems) {
            $msg = str_pad($this->printing->colorize($indexer, $this->printing::SUCCESS), 35, ' ', STR_PAD_RIGHT) . PHP_EOL;
            foreach ($indexerItems as $indexerItem) {
                $msg .= $this->printing->colorize($indexerItem, $this->printing::NOTE) . PHP_EOL;
            }
            $this->printing->printing($msg);
        }
        $break = true;
        $data->setData('break', $break);
    }
}

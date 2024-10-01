<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Event\Console\Event;

use Weline\Framework\Event\Config\XmlReader;
use Weline\Framework\Output\Cli\Printing;

class Data implements \Weline\Framework\Console\CommandInterface
{
    /**
     * @var Printing
     */
    private Printing $printing;

    /**
     * @var XmlReader
     */
    private XmlReader $reader;

    public function __construct(
        XmlReader $reader,
        Printing  $printing
    )
    {
        $this->printing = $printing;
        $this->reader   = $reader;
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $eventList = $this->reader->read();
        array_shift($args);
        if ($args) {
            foreach ($eventList as $key => $item) {
                $key_arr     = explode('::', $key);
                $module_name = array_shift($key_arr);
                foreach ($args as $module) {
                    if ($module_name === $module) {
                        $this->printing->setup(__('事件监听文件：') . $key);
                        foreach ($item as $k => $i) {
                            $this->printing->printing(__('事件名：') . $k);
                            $this->printing->printList($i);
                        }
                    }
                }
            }
        } else {
            foreach ($eventList as $key => $item) {
                $this->printing->setup(__('事件监听文件：') . $key);
                foreach ($item as $k => $i) {
                    $this->printing->printing(__('事件名：') . $k);
                    $this->printing->printList($i);
                }
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('事件观察者列表！具体模组的事件请在命令后写明。例如：（ php bin/m event:data Weline_Core Weline_Base）');
    }
}

<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Event\Config;

use Weline\Framework\Cache\CacheInterface;
use Weline\Framework\Event\Cache\EventCache;
use Weline\Framework\Exception\Core;
use Weline\Framework\System\File\Scanner;
use Weline\Framework\Xml\Parser;

class XmlReader extends \Weline\Framework\Config\Reader\XmlReader
{
    /**
     * @var CacheInterface
     */
    private CacheInterface $eventCache;

    public function __construct(
        EventCache $eventCache,
        Scanner    $scanner,
        Parser     $parser,
                   $path = 'etc' . DS . 'event.xml'
    )
    {
        parent::__construct($scanner, $parser, $path);
        $this->eventCache = $eventCache->create();
    }

    /**
     * @DESC         |读取事件配置
     *
     * 开发者模式读取真实配置
     * 非开发者模式有缓存则读取缓存
     * 参数区：
     *
     * @param bool $cache
     *
     * @return mixed
     * @throws Core
     */
    public function read(): array
    {
//        if ($event = $this->eventCache->get('event')) {
//            return $event;
//        }
        # 模块配置文件
        $configs = parent::read();
        // 合并掉所有相同名字的事件的观察者，方便获取
        $event_observers_list = [];
        foreach ($configs as $module_and_file => $config) {
            $module_event_observers = [];
            if (
                !isset($event_xml_data['config']['_attribute']['noNamespaceSchemaLocation']) &&
                'urn:Weline_Framework::Event/etc/xsd/event.xsd' !== $config['config']['_attribute']['noNamespaceSchemaLocation']
            ) {
                throw new Core(__($module_and_file . '事件必须设置：noNamespaceSchemaLocation="urn:Weline_Framework::Event/etc/xsd/event.xsd"'));
            }
            // 多个值
            if (is_integer(array_key_first($config['config']['_value']['event']))) {
                foreach ($config['config']['_value']['event'] as $event) {
                    if (!isset($event['_attribute']['name'])) {
                        throw new Core(__($module_and_file . '事件Event未指定name属性：<event name="eventName">...</event>'));
                    }
                    // 多个值
                    if (is_integer(array_key_first($event['_value']))) {
                        foreach ($event['_value'] as $item_observer) {
                            $module_event_observers[$event['_attribute']['name']][] = $item_observer;
                        }
                    } else {
                        if (!isset($event['_value']['observer']['_attribute'])) {
                            throw new Core(__($module_and_file . '观察者Observer没有设置属性：<observer name="observerName" instance="instanceClass" disabled="false" shared="true"/>'));
                        }
                        if (!isset($event['_value']['observer']['_attribute']['name'])) {
                            throw new Core(__($module_and_file . '观察者Observer没有设置name属性：<observer name="observerName" instance="instanceClass" disabled="false" shared="true"/>'));
                        }
                        if (!isset($event['_value']['observer']['_attribute']['instance'])) {
                            throw new Core(__($module_and_file . '观察者Observer没有设置instance属性：<observer name="observerName" instance="instanceClass" disabled="false" shared="true"/>'));
                        }
                        $event['_value']['observer']['_attribute']['sort']      = $event['_value']['observer']['_attribute']['sort'] ?? 10000;
                        $module_event_observers[$event['_attribute']['name']][] = $event['_value']['observer']['_attribute'];
                    }
                }
            } else {
                if (!isset($config['config']['_value']['event']['_attribute']['name'])) {
                    throw new Core(__($module_and_file . '事件Event未指定name属性：<event name="eventName">...</event>'));
                }
                if (!isset($config['config']['_value']['event']['_value']['observer']['_attribute'])) {
                    throw new Core(__($module_and_file . '观察者Observer没有设置属性：<observer name="observerName" instance="instanceClass" disabled="false" shared="true"/>'));
                }
                if (!isset($config['config']['_value']['event']['_value']['observer']['_attribute']['name'])) {
                    throw new Core(__($module_and_file . '观察者Observer没有设置name属性：<observer name="observerName" instance="instanceClass" disabled="false" shared="true"/>'));
                }
                if (!isset($config['config']['_value']['event']['_value']['observer']['_attribute']['instance'])) {
                    throw new Core(__($module_and_file . '观察者Observer没有设置instance属性：<observer name="observerName" instance="instanceClass" disabled="false" shared="true"/>'));
                }
                $config['config']['_value']['event']['_value']['observer']['_attribute']['sort'] = $config['config']['_value']['event']['_value']['observer']['_attribute']['sort']?? 10000;
                $module_event_observers[$config['config']['_value']['event']['_attribute']['name']][] = $config['config']['_value']['event']['_value']['observer']['_attribute'];
            }
            $event_observers_list[$module_and_file] = $module_event_observers;
        }
        $this->eventCache->set('event', $event_observers_list);
        return $event_observers_list;
    }
}

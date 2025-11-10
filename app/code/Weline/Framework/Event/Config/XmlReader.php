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
     */
    public function read(): array
    {
        // 临时禁用缓存以便调试
        // if ($event = $this->eventCache->get('event')) {
        //     return $event;
        // }
        # 模块配置文件
        try {
            $configs = parent::read();
        } catch (\Throwable $e) {
            error_log('事件配置读取失败: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
            throw $e;
        }
        // 合并掉所有相同名字的事件的观察者，方便获取
        $event_observers_list = [];
        foreach ($configs as $module_and_file => $config) {
            $module_event_observers = [];
            // 跳过没有正确格式的配置
            if (!isset($config['config']) || !is_array($config['config'])) {
                error_log(__('跳过格式不正确的配置文件：%{1}', [$module_and_file]));
                continue;
            }
            if (!isset($config['config']['_attribute']) || !is_array($config['config']['_attribute'])) {
                error_log(__('跳过缺少属性的配置文件：%{1}', [$module_and_file]));
                continue;
            }
            if (
                !isset($config['config']['_attribute']['noNamespaceSchemaLocation']) ||
                'urn:Weline_Framework::Event/etc/xsd/event.xsd' !== $config['config']['_attribute']['noNamespaceSchemaLocation']
            ) {
                die(__('%{1} 事件必须设置：noNamespaceSchemaLocation="urn:Weline_Framework::Event/etc/xsd/event.xsd"', [$module_and_file]));
            }
            // 检查 event 是否存在
            if (!isset($config['config']['_value']['event'])) {
                die(__('%{1} 事件配置文件缺少event节点', [$module_and_file]));
            }
            // 多个值
            $firstEventKey = array_key_first($config['config']['_value']['event']);
            if ($firstEventKey !== null && is_integer($firstEventKey)) {
                foreach ($config['config']['_value']['event'] as $event) {
                    if (!isset($event['_attribute']['name'])) {
                        die(__('%{1} 事件Event未指定name属性：<event name="eventName">...</event>', [$module_and_file]));
                    }
                    // 检查 _value 是否存在
                    if (!isset($event['_value']) || !is_array($event['_value'])) {
                        die(__('%{1} 事件Event的_value格式错误', [$module_and_file]));
                    }
                    // 检查 observer 节点是否存在
                    if (!isset($event['_value']['observer'])) {
                        die(__('%{1} 事件Event缺少observer节点', [$module_and_file]));
                    }
                    // 处理 observer（可能是单个或多个）
                    $observers = $event['_value']['observer'];
                    // 检查是否是多个 observer（数组，第一个键是整数）
                    $firstObserverKey = array_key_first($observers);
                    if ($firstObserverKey !== null && is_integer($firstObserverKey)) {
                        // 多个 observer
                        foreach ($observers as $item_observer) {
                            if (!isset($item_observer['_attribute'])) {
                                die(__('%{1} 观察者Observer没有设置属性：<observer name="observerName" instance="instanceClass" disabled="false" shared="true" sort="100"/>', [$module_and_file]));
                            }
                            if (!isset($item_observer['_attribute']['name'])) {
                                die(__('%{1} 观察者Observer没有设置name属性：<observer name="observerName" instance="instanceClass" disabled="false" shared="true" sort="100"/>', [$module_and_file]));
                            }
                            if (!isset($item_observer['_attribute']['instance'])) {
                                die(__('%{1} 观察者Observer没有设置instance属性：<observer name="observerName" instance="instanceClass" disabled="false" shared="true" sort="100"/>', [$module_and_file]));
                            }
                            // 设置默认值
                            $item_observer['_attribute']['disabled'] = $item_observer['_attribute']['disabled'] ?? 'false';
                            $item_observer['_attribute']['shared'] = $item_observer['_attribute']['shared'] ?? 'true';
                            $item_observer['_attribute']['sort'] = $item_observer['_attribute']['sort'] ?? 10000;
                            $module_event_observers[$event['_attribute']['name']][] = $item_observer['_attribute'];
                        }
                    } else {
                        // 单个 observer
                        if (!isset($observers['_attribute'])) {
                            die(__('%{1} 观察者Observer没有设置属性：<observer name="observerName" instance="instanceClass" disabled="false" shared="true" sort="100"/>', [$module_and_file]));
                        }
                        if (!isset($observers['_attribute']['name'])) {
                            die(__('%{1} 观察者Observer没有设置name属性：<observer name="observerName" instance="instanceClass" disabled="false" shared="true" sort="100"/>', [$module_and_file]));
                        }
                        if (!isset($observers['_attribute']['instance'])) {
                            die(__('%{1} 观察者Observer没有设置instance属性：<observer name="observerName" instance="instanceClass" disabled="false" shared="true" sort="100"/>', [$module_and_file]));
                        }
                        // 设置默认值
                        $observers['_attribute']['disabled'] = $observers['_attribute']['disabled'] ?? 'false';
                        $observers['_attribute']['shared'] = $observers['_attribute']['shared'] ?? 'true';
                        $observers['_attribute']['sort'] = $observers['_attribute']['sort'] ?? 10000;
                        $module_event_observers[$event['_attribute']['name']][] = $observers['_attribute'];
                    }
                }
            } else {
                // 单个 event
                if (!isset($config['config']['_value']['event']['_attribute']['name'])) {
                    die(__('%{1} 事件Event未指定name属性：<event name="eventName">...</event>', [$module_and_file]));
                }
                // 检查 _value 是否存在
                if (!isset($config['config']['_value']['event']['_value']) || !is_array($config['config']['_value']['event']['_value'])) {
                    die(__('%{1} 事件Event的_value格式错误', [$module_and_file]));
                }
                // 检查 observer 节点是否存在
                if (!isset($config['config']['_value']['event']['_value']['observer'])) {
                    die(__('%{1} 事件Event缺少observer节点', [$module_and_file]));
                }
                // 处理 observer（可能是单个或多个）
                $observers = $config['config']['_value']['event']['_value']['observer'];
                // 检查是否是多个 observer（数组，第一个键是整数）
                $firstObserverKey = array_key_first($observers);
                if ($firstObserverKey !== null && is_integer($firstObserverKey)) {
                    // 多个 observer
                    foreach ($observers as $item_observer) {
                        if (!isset($item_observer['_attribute'])) {
                            die(__('%{1} 观察者Observer没有设置属性：<observer name="observerName" instance="instanceClass" disabled="false" shared="true" sort="100"/>', [$module_and_file]));
                        }
                        if (!isset($item_observer['_attribute']['name'])) {
                            die(__('%{1} 观察者Observer没有设置name属性：<observer name="observerName" instance="instanceClass" disabled="false" shared="true" sort="100"/>', [$module_and_file]));
                        }
                        if (!isset($item_observer['_attribute']['instance'])) {
                            die(__('%{1} 观察者Observer没有设置instance属性：<observer name="observerName" instance="instanceClass" disabled="false" shared="true" sort="100"/>', [$module_and_file]));
                        }
                        // 设置默认值
                        $item_observer['_attribute']['disabled'] = $item_observer['_attribute']['disabled'] ?? 'false';
                        $item_observer['_attribute']['shared'] = $item_observer['_attribute']['shared'] ?? 'true';
                        $item_observer['_attribute']['sort'] = $item_observer['_attribute']['sort'] ?? 10000;
                        $module_event_observers[$config['config']['_value']['event']['_attribute']['name']][] = $item_observer['_attribute'];
                    }
                } else {
                    // 单个 observer
                    if (!isset($observers['_attribute'])) {
                        die(__('%{1} 观察者Observer没有设置属性：<observer name="observerName" instance="instanceClass" disabled="false" shared="true" sort="100"/>', [$module_and_file]));
                    }
                    if (!isset($observers['_attribute']['name'])) {
                        die(__('%{1} 观察者Observer没有设置name属性：<observer name="observerName" instance="instanceClass" disabled="false" shared="true" sort="100"/>', [$module_and_file]));
                    }
                    if (!isset($observers['_attribute']['instance'])) {
                        die(__('%{1} 观察者Observer没有设置instance属性：<observer name="observerName" instance="instanceClass" disabled="false" shared="true" sort="100"/>', [$module_and_file]));
                    }
                    // 设置默认值
                    $observers['_attribute']['disabled'] = $observers['_attribute']['disabled'] ?? 'false';
                    $observers['_attribute']['shared'] = $observers['_attribute']['shared'] ?? 'true';
                    $observers['_attribute']['sort'] = $observers['_attribute']['sort'] ?? 10000;
                    $module_event_observers[$config['config']['_value']['event']['_attribute']['name']][] = $observers['_attribute'];
                }
            }
            $event_observers_list[$module_and_file] = $module_event_observers;
        }
        $this->eventCache->set('event', $event_observers_list);
        return $event_observers_list;
    }
}

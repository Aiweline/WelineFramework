<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Event\Config;

<<<<<<< HEAD
=======
use Weline\Framework\App\Env;
>>>>>>> dev-new
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
<<<<<<< HEAD
=======
            // 提取模块名（格式：ModuleName::path/to/file.xml）
            $moduleName = explode('::', $module_and_file)[0] ?? '';
            if (empty($moduleName)) {
                error_log(__('无法从文件路径提取模块名：%{1}', [$module_and_file]));
                continue;
            }

>>>>>>> dev-new
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
<<<<<<< HEAD
                die(__('%{1} 事件配置文件缺少event节点', [$module_and_file]));
            }
=======
                // 如果 event.xml 文件存在但没有 event 节点，说明该模块不定义事件，跳过处理
                continue;
            }

            // 加载模块的 event.php 规约文件
            $eventSpecs = $this->loadModuleEventSpecs($moduleName);
>>>>>>> dev-new
            // 多个值
            $firstEventKey = array_key_first($config['config']['_value']['event']);
            if ($firstEventKey !== null && is_integer($firstEventKey)) {
                foreach ($config['config']['_value']['event'] as $event) {
                    if (!isset($event['_attribute']['name'])) {
                        die(__('%{1} 事件Event未指定name属性：<event name="eventName">...</event>', [$module_and_file]));
                    }
<<<<<<< HEAD
=======
                    
                    $eventName = $event['_attribute']['name'];
                    
                    // 验证事件名是否在规约文件中存在
                    $this->validateEventSpec($eventName, $moduleName, $eventSpecs, $module_and_file);
                    
>>>>>>> dev-new
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
<<<<<<< HEAD
=======
                
                $eventName = $config['config']['_value']['event']['_attribute']['name'];
                
                // 验证事件名是否在规约文件中存在
                $this->validateEventSpec($eventName, $moduleName, $eventSpecs, $module_and_file);
                
>>>>>>> dev-new
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
<<<<<<< HEAD
=======

    /**
     * 加载模块的 event.php 规约文件
     *
     * @param string $moduleName 模块名
     * @return array 事件规约数组，键为事件名，值为事件配置
     */
    private function loadModuleEventSpecs(string $moduleName): array
    {
        try {
            $env = Env::getInstance();
            $moduleInfo = $env->getModuleInfo($moduleName);
            $basePath = $moduleInfo['base_path'] ?? '';
            
            if (empty($basePath)) {
                return [];
            }
            
            $eventFile = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR . 'event.php';
            if (!file_exists($eventFile)) {
                return [];
            }
            
            $config = include $eventFile;
            return is_array($config) ? $config : [];
        } catch (\Exception $e) {
            return [];
        }
    }

    /**
     * 检查事件名是否匹配动态事件模式
     *
     * @param string $eventName 事件名（如 Framework_View::header）
     * @param string $pattern 动态事件模式（如 Framework_View::{position}）
     * @return bool 是否匹配
     */
    private function matchDynamicEventPattern(string $eventName, string $pattern): bool
    {
        // 检查模式是否包含动态占位符 {xxx}
        if (strpos($pattern, '{') === false || strpos($pattern, '}') === false) {
            return false; // 不是动态模式
        }
        
        // 将模式转换为正则表达式
        // Framework_View::{position} -> ^Framework_View::.*$
        // 先转义特殊字符，但保留 {xxx} 占位符
        $parts = preg_split('/(\{[^}]+\})/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE);
        $regexParts = [];
        foreach ($parts as $part) {
            if (preg_match('/^\{[^}]+\}$/', $part)) {
                // 这是占位符，替换为 .*
                $regexParts[] = '.*';
            } else {
                // 转义特殊字符
                $regexParts[] = preg_quote($part, '/');
            }
        }
        $regex = '/^' . implode('', $regexParts) . '$/';
        
        return (bool)preg_match($regex, $eventName);
    }

    /**
     * 查找定义事件的模块
     *
     * @param string $eventName 事件名
     * @return string|null 定义事件的模块名，如果找不到则返回null
     */
    private function findEventDefiningModule(string $eventName): ?string
    {
        try {
            $env = Env::getInstance();
            $modules = $env->getModuleList();
            
            foreach ($modules as $moduleName => $moduleInfo) {
                $eventSpecs = $this->loadModuleEventSpecs($moduleName);
                if (empty($eventSpecs)) {
                    continue;
                }
                
                // 精确匹配
                if (isset($eventSpecs[$eventName])) {
                    return $moduleName;
                }
                
                // 动态事件模式匹配
                foreach ($eventSpecs as $pattern => $spec) {
                    if ($this->matchDynamicEventPattern($eventName, $pattern)) {
                        return $moduleName;
                    }
                }
            }
        } catch (\Exception $e) {
            // 忽略异常，继续查找
        }
        
        return null;
    }

    /**
     * 验证事件名是否在规约文件中存在
     *
     * @param string $eventName 事件名
     * @param string $moduleName 使用事件的模块名（event.xml所在的模块）
     * @param array $eventSpecs 事件规约数组（使用事件的模块的规约）
     * @param string $module_and_file 模块和文件路径（用于错误提示）
     * @return void
     * @throws \RuntimeException 如果事件名不在规约文件中
     */
    private function validateEventSpec(string $eventName, string $moduleName, array $eventSpecs, string $module_and_file): void
    {
        // 首先检查使用事件的模块是否定义了该事件
        if (!empty($eventSpecs) && isset($eventSpecs[$eventName])) {
            return; // 找到了，验证通过
        }
        
        // 如果使用事件的模块没有定义，则查找定义该事件的模块
        $definingModule = $this->findEventDefiningModule($eventName);
        
        if ($definingModule !== null) {
            // 找到了定义事件的模块，验证通过
            return;
        }
        
        // 如果都找不到，检查使用事件的模块是否有 event.php 文件
        if (empty($eventSpecs)) {
            $errorMessage = sprintf(
                '事件 "%s" 在模块 "%s" 的 event.xml 中定义，但找不到对应的事件规约文件。' . "\n" .
                '该事件需要在定义该事件的模块的 event.php 文件中定义规约，或者在模块 "%s" 的根目录创建 event.php 文件并定义事件 "%s" 的规约。' . "\n" .
                '文件路径：%s',
                $eventName,
                $moduleName,
                $moduleName,
                $eventName,
                $module_and_file
            );
            throw new \RuntimeException($errorMessage);
        }
        
        // 使用事件的模块有 event.php 文件，但没有定义该事件
        $errorMessage = sprintf(
            '事件 "%s" 在模块 "%s" 的 event.xml 中定义，但在 event.php 规约文件中找不到对应的事件定义。' . "\n" .
            '该事件需要在定义该事件的模块的 event.php 文件中定义规约，或者在模块 "%s" 的根目录的 event.php 文件中添加事件 "%s" 的规约定义。' . "\n" .
            '文件路径：%s' . "\n" .
            '规约文件示例：' . "\n" .
            '<?php' . "\n" .
            'return [' . "\n" .
            '    \'%s\' => [' . "\n" .
            '        \'name\' => __(\'事件显示名\'),' . "\n" .
            '        \'description\' => __(\'事件描述\'),' . "\n" .
            '        \'doc\' => \'事件文档.md\',' . "\n" .
            '    ],' . "\n" .
            '];',
            $eventName,
            $moduleName,
            $moduleName,
            $eventName,
            $module_and_file,
            $eventName
        );
        throw new \RuntimeException($errorMessage);
    }
>>>>>>> dev-new
}

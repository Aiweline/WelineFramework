<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Event\Config;

use Weline\Framework\App\Env;
use Weline\Framework\Cache\Contract\CachePoolInterface;
use Weline\Framework\System\File\Scanner;
use Weline\Framework\Xml\Parser;

class XmlReader extends \Weline\Framework\Config\Reader\XmlReader
{
    /**
     * @var CachePoolInterface
     */
    private CachePoolInterface $eventCache;
    
    /**
     * 静态变量：记录已经输出过的错误信息，避免重复输出
     * @var array
     */
    private static array $loggedErrors = [];

    private const RELATIVE_PATH = 'etc' . DIRECTORY_SEPARATOR . 'event.xml';

    public function __construct(
        Scanner    $scanner,
        Parser     $parser,
                   $path = 'etc' . DS . 'event.xml'
    )
    {
        parent::__construct($scanner, $parser, $path);
        $this->eventCache = w_cache('event');
    }

    /**
     * 获取 event.xml 文件列表：仅激活模块，用 base_path + etc/event.xml 直接定位，不扫描目录。
     *
     * @param \Closure|null $callback 保留签名兼容，此处未使用
     * @return array<string, string> 模块名 => 文件绝对路径
     */
    public function getFileList(null|\Closure $callback = null): array
    {
        $result = [];
        $modules = Env::getInstance()->getActiveModules();
        $order = ['app' => 0, 'framework' => 1, 'system' => 2, 'composer' => 3];
        uasort($modules, static fn($a, $b) => ($order[$a['position'] ?? 'composer'] ?? 4) <=> ($order[$b['position'] ?? 'composer'] ?? 4));
        foreach ($modules as $module) {
            $name = $module['name'] ?? '';
            $basePath = rtrim($module['base_path'] ?? '', '/\\');
            if ($name === '' || $basePath === '') {
                continue;
            }
            $filePath = $basePath . DIRECTORY_SEPARATOR . self::RELATIVE_PATH;
            if (is_file($filePath)) {
                $result[$name] = $filePath;
            }
        }
        return $callback ? $callback($result) : $result;
    }

    /**
     * 读取事件配置：仅激活模块，base_path 直接定位文件，逐文件解析合并，降低内存占用。
     */
    public function read(): array
    {
        $event_observers_list = [];
        $fileList = $this->getFileList();
        $parser = $this->parser;
        foreach ($fileList as $moduleName => $filePath) {
            try {
                $config = $parser->load($filePath)->xmlToArray();
            } catch (\Throwable $e) {
                w_log_error('事件配置读取失败: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
                throw $e;
            }
            $module_and_file = $moduleName . '::' . $filePath;
            $module_event_observers = $this->processOneFileConfig($config, $moduleName, $module_and_file, $filePath);
            if ($module_event_observers !== null) {
                $event_observers_list[$module_and_file] = $module_event_observers;
            }
        }
        $this->eventCache->set('event', $event_observers_list);
        return $event_observers_list;
    }

    /**
     * 处理单个 event.xml 解析结果，返回该文件对应的事件观察者数组，无效则返回 null。
     */
    private function processOneFileConfig(array $config, string $moduleName, string $module_and_file, string $filePath): ?array
    {
        if (!isset($config['config']) || !is_array($config['config'])) {
            $isEmpty = $filePath !== '' && file_exists($filePath) && empty(trim((string) file_get_contents($filePath)));
            $errorKey = $module_and_file . ($isEmpty ? '_empty' : '_invalid_format');
            if (!isset(self::$loggedErrors[$errorKey])) {
                w_log_warning($isEmpty
                    ? __('跳过空的事件配置文件：%{1}（文件为空，如需使用请添加有效内容）', [$module_and_file])
                    : __('跳过格式不正确的配置文件：%{1}（请检查XML格式是否正确）', [$module_and_file]));
                self::$loggedErrors[$errorKey] = true;
            }
            return null;
        }
        if (!isset($config['config']['_attribute']) || !is_array($config['config']['_attribute'])) {
            if (!isset(self::$loggedErrors[$module_and_file . '_missing_attributes'])) {
                w_log_warning(__('跳过缺少属性的配置文件：%{1}', [$module_and_file]));
                self::$loggedErrors[$module_and_file . '_missing_attributes'] = true;
            }
            return null;
        }
        if (
            !isset($config['config']['_attribute']['noNamespaceSchemaLocation'])
            || 'urn:Weline_Framework::Event/etc/xsd/event.xsd' !== $config['config']['_attribute']['noNamespaceSchemaLocation']
        ) {
            die(__('%{1} 事件必须设置：noNamespaceSchemaLocation="urn:Weline_Framework::Event/etc/xsd/event.xsd"', [$module_and_file]));
        }
        if (!isset($config['config']['_value']['event'])) {
            return null;
        }

        $module_event_observers = [];
        $eventSpecs = $this->loadModuleEventSpecs($moduleName);
        $firstEventKey = array_key_first($config['config']['_value']['event']);
        if ($firstEventKey !== null && is_int($firstEventKey)) {
            foreach ($config['config']['_value']['event'] as $event) {
                if (!isset($event['_attribute']['name'])) {
                    die(__('%{1} 事件Event未指定name属性：<event name="eventName">...</event>', [$module_and_file]));
                }
                try {
                    $this->validateEventSpec($event['_attribute']['name'], $moduleName, $eventSpecs, $module_and_file);
                } catch (\RuntimeException $e) {
                    w_log_warning('事件规约验证警告: ' . $e->getMessage(), [], 'event_spec_validation.log');
                }
                if (!isset($event['_value']) || !is_array($event['_value']) || !isset($event['_value']['observer'])) {
                    die(__('%{1} 事件Event的_value格式错误或缺少observer节点', [$module_and_file]));
                }
                $observers = $event['_value']['observer'];
                $this->collectObservers($observers, $event['_attribute']['name'], $module_event_observers, $module_and_file);
            }
        } else {
            $eventNode = $config['config']['_value']['event'];
            if (!isset($eventNode['_attribute']['name'])) {
                die(__('%{1} 事件Event未指定name属性：<event name="eventName">...</event>', [$module_and_file]));
            }
            try {
                $this->validateEventSpec($eventNode['_attribute']['name'], $moduleName, $eventSpecs, $module_and_file);
            } catch (\RuntimeException $e) {
                w_log_warning('事件规约验证警告: ' . $e->getMessage(), [], 'event_spec_validation.log');
            }
            if (!isset($eventNode['_value']) || !is_array($eventNode['_value']) || !isset($eventNode['_value']['observer'])) {
                die(__('%{1} 事件Event的_value格式错误或缺少observer节点', [$module_and_file]));
            }
            $observers = $eventNode['_value']['observer'];
            $this->collectObservers($observers, $eventNode['_attribute']['name'], $module_event_observers, $module_and_file);
        }
        return $module_event_observers;
    }

    /**
     * 将 observer 节点（单个或多个）合并到 $module_event_observers[$eventName]。
     */
    private function collectObservers(
        array $observers,
        string $eventName,
        array &$module_event_observers,
        string $module_and_file
    ): void {
        $firstKey = array_key_first($observers);
        $list = ($firstKey !== null && is_int($firstKey)) ? $observers : [$observers];
        foreach ($list as $item) {
            if (!isset($item['_attribute']['name'], $item['_attribute']['instance'])) {
                die(__('%{1} 观察者Observer没有设置name/instance属性：<observer name="..." instance="..."/>', [$module_and_file]));
            }
            $attr = $item['_attribute'];
            $attr['disabled'] = $attr['disabled'] ?? 'false';
            $attr['shared'] = $attr['shared'] ?? 'true';
            $attr['sort'] = $attr['sort'] ?? 10000;
            $module_event_observers[$eventName][] = $attr;
        }
    }

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
            $modules = Env::getInstance()->getActiveModules();
            foreach ($modules as $module) {
                $moduleName = $module['name'] ?? '';
                if ($moduleName === '') {
                    continue;
                }
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
}

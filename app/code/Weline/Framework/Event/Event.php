<?php

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Framework\Event;

use Weline\Framework\App\Env;
use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Console\Event\Data;
use Weline\Framework\Exception\Core;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Debug\Printing;
use Weline\Framework\Registry\Service\RegistryModulePresence;
use Weline\Framework\Runtime\RequestLifecycleTrace;

class Event extends \Weline\Framework\DataObject\DataObject
{
    public function __construct(array|string $data = [], array $legacyData = [])
    {
        $legacyName = null;
        if (\is_string($data)) {
            $legacyName = $data;
            $data = $legacyData;
            $data['name'] ??= $legacyName;
        }

        // 保持观察者配置数组结构，不在此处实例化
        // 实例化将在 dispatch() 方法中按需进行
        if (isset($data['observers'])) {
            // 确保 observers 是数组
            if (!is_array($data['observers'])) {
                $data['observers'] = [];
            }
            // 保持原始配置数组结构，不进行实例化
        }
        parent::__construct($data);

        if ($legacyName !== null) {
            $this->setName($legacyName);
        } elseif (isset($data['name']) && \is_string($data['name'])) {
            $this->setName($data['name']);
        }
    }

    public function getData(string $key = '', $index = null): mixed
    {
        $res = $this->getEvenData($key);
        if ($res === null) {
            return parent::getData($key, $index);
        }
        return $res;
    }

    public function setData(array|string $key, mixed $value = null): static
    {
        $eventData = $this->_getData('data');
        if (!$eventData) {
            if (is_array($key)) {
                $data = $key;
            } else {
                $data = [$key => $value];
            }
            $this->_data['data'] = $data;
            return $this;
        }
        if (is_array($key)) {
            if ($eventData instanceof DataObject) {
                $eventData->setData($key);
            } elseif (is_array($eventData)) {
                foreach ($key as $k => $item) {
                    $eventData[$k] = $item;
                }
            } else {
                $eventData = $value;
            }
            $this->_data['data'] = $eventData;
            return $this;
        } else if ($eventData instanceof DataObject) {
            $eventData->setData($key, $value);
        } elseif (is_array($eventData)) {
            $eventData[$key] = $value;
        } else {
            $eventData = $value;
        }
        $this->_data['data'] = $eventData;
        return $this;
    }

    public function getEvenData(string $key = ''): mixed
    {
        $eventData = $this->_getData('data');
        if ($key and $eventData instanceof DataObject) {
            return $eventData->getData($key);
        }
        if (isset($eventData[$key])) {
            return $eventData[$key];
        }
        return $eventData;
    }

    private string $name;

    /**
     * @DESC         |添加观察者
     *
     * 参数区：
     *
     * @param Observer $observer
     *
     * @return Event
     */
    public function addObserver(Observer $observer): static
    {
        $observers = $this->_getData('observers');
        $observers[] = $observer;
        $this->setData('observers', $observers);

        return $this;
    }

    /**
     * @DESC         |获取观察者
     *
     * 参数区：
     *
     * @return ObserverInterface []
     */
    public function getObservers(): array
    {
        return $this->_getData('observers');
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @DESC         |方法描述
     *
     * 参数区：
     *
     * @param string $name
     *
     * @return $this
     */
    public function setName(string $name)
    {
        $this->name = $name;

        return $this;
    }

    /**
     * @DESC         |派遣
     *
     * 参数区：
     */
    public function dispatch(): void
    {
        $observers = $this->getObservers();

        // 确保观察者是数组
        if (!is_array($observers)) {
            $observers = [];
        }
        
        // 获取配置中的 event.debug 设置（使用静态缓存，避免每次 dispatch 都读取配置）
        static $eventDebugConfig = null;
        if ($eventDebugConfig === null) {
            $eventDebugConfig = Env::dev('event_debug');
        }
        
        // 判断是否需要调试
        if ($eventDebugConfig === true) {
            // 配置为 true 时，直接打印和记录
            $needDebug = true;
            $needLog = true;
            $printToConsole = true;
        } else {
            // 配置为 false 时，检查参数
            $isCliDebugMode = false;
            $isWebDebugMode = false;
            
            if (PHP_SAPI === 'cli') {
                // CLI 模式：检查命令行参数中是否有 event-debug
                global $argv;
                $isCliDebugMode = isset($argv) && in_array('--event-debug', $argv);
            } else {
                // Web 模式：检查 GET 参数中是否有 event-debug
                $isWebDebugMode = \w_env_get('event-debug') !== null;
            }
            
            $needDebug = $isCliDebugMode || $isWebDebugMode;
            $needLog = $isCliDebugMode || $isWebDebugMode;
            $printToConsole = $isCliDebugMode;
        }
        
        if ($printToConsole && !empty($observers)) {
            $this->printEventHeader();
        }
        
        if ($needLog && !empty($observers)) {
            $this->initLogFile();
        }
        
        // 遍历观察者配置，按需实例化并执行
        // 注意：模块激活状态已在 EventsManager::filterActiveObservers() 中过滤，此处不再重复检查
        
        foreach ($observers as $index => $observerConfig) {
            try {
                // 检查是否被禁用（保留 disabled 检查作为最后防线）
                if (isset($observerConfig['disabled']) && $observerConfig['disabled'] === 'true') {
                    continue;
                }
                
                // 按需实例化观察者
                $observerClass = $this->getUsableObserverClass($observerConfig);
                if ($observerClass === null) {
                    continue;
                }
                $observer = ObjectManager::getInstance($observerClass);
                
                if (!($observer instanceof ObserverInterface)) {
                    if (DEV) {
                        throw new Core(__('观察者必须继承于：') . ObserverInterface::class);
                    } else {
                        $debug = ObjectManager::getInstance(Printing::class);
                        $debug->debug(__('观察者必须继承于：') . ObserverInterface::class);
                    }
                    continue;
                }
                
                $observerSpanStart = RequestLifecycleTrace::isEnabled() ? microtime(true) : 0.0;
                $observerName = str_replace('\\', '::', get_class($observer));
                $observerSpanName = 'observer::' . $observerName;
                if (RequestLifecycleTrace::isEnabled()) {
                    RequestLifecycleTrace::pushCurrentParent($observerSpanName);
                }
                try {
                    // 执行观察者（其内派发的事件会挂到本观察者下，回到此处才算观察者结束）
                    if ($needDebug) {
                        $this->executeWithDebug($observer, $printToConsole, $needLog);
                    } else {
                        $observer->execute($this);
                    }
                } finally {
                    if (RequestLifecycleTrace::isEnabled()) {
                        RequestLifecycleTrace::popCurrentParent();
                    }
                }
                if ($observerSpanStart > 0) {
                    $observerDurationMs = (microtime(true) - $observerSpanStart) * 1000;
                    RequestLifecycleTrace::recordSpan($observerSpanName, $observerDurationMs, 'observer', 'event::' . $this->getName());
                }
            } catch (\Exception $e) {
                // 实例化失败，跳过该观察者
                throw $e;
            }
        }
        
        if ($printToConsole && !empty($observers)) {
            echo "\n";
        }
    }
    
    /**
     * @DESC         |打印事件头部信息
     */
    private function getUsableObserverClass(mixed $observerConfig): ?string
    {
        if (!is_array($observerConfig)) {
            w_log_warning(__('事件 %{1} 跳过无效观察者配置。', [$this->getName()]), [], 'event_dispatch.log');
            return null;
        }

        $instance = ltrim(trim((string)($observerConfig['instance'] ?? '')), '\\');
        if ($instance === '') {
            w_log_warning(__('事件 %{1} 跳过缺少 instance 的观察者配置。', [$this->getName()]), [], 'event_dispatch.log');
            return null;
        }

        if (!RegistryModulePresence::classExists($instance)) {
            w_log_warning(__('事件 %{1} 跳过不存在的观察者类：%{2}', [
                $this->getName(),
                $instance,
            ]), [], 'event_dispatch.log');
            return null;
        }

        return $instance;
    }

    private function printEventHeader(): void
    {
        echo "\n";
        echo __("Event Name: %{1}", [$this->getName()]) . "\n";
        echo str_repeat("-", 80) . "\n";
        echo sprintf("%-50s %-15s %-15s %s\n", __("Class Name"), __("Start Time"), __("End Time"), __("Duration"));
        echo str_repeat("-", 80) . "\n";
    }
    
    /**
     * @DESC         |带调试信息执行观察者
     *
     * @param ObserverInterface $observer
     * @param bool $printToConsole 是否输出到控制台
     * @param bool $writeToLog 是否写入日志
     */
    private function executeWithDebug(ObserverInterface $observer, bool $printToConsole = false, bool $writeToLog = false): void
    {
        $className = get_class($observer);
        // 简化类名显示
        $shortClassName = substr($className, strrpos($className, '\\') + 1);
        
        $startTime = microtime(true);
        $startTimeFormatted = date('H:i:s.') . substr(number_format($startTime, 3), -3);
        
        try {
            $observer->execute($this);
            
            $endTime = microtime(true);
            $endTimeFormatted = date('H:i:s.') . substr(number_format($endTime, 3), -3);
            $duration = number_format(($endTime - $startTime) * 1000, 3) . 'ms';
            
            $output = sprintf("%-50s %-15s %-15s %s\n", 
                $shortClassName, 
                $startTimeFormatted, 
                $endTimeFormatted,
                $duration
            );
            
            if ($printToConsole) {
                echo $output;
            }
            
            if ($writeToLog) {
                $this->writeToLog($output);
            }
        } catch (\Throwable $e) {
            $endTime = microtime(true);
            $endTimeFormatted = date('H:i:s.') . substr(number_format($endTime, 3), -3);
            $duration = number_format(($endTime - $startTime) * 1000, 3) . 'ms';
            
            $output = sprintf("%-50s %-15s %-15s %s [ERROR: %s]\n", 
                $shortClassName, 
                $startTimeFormatted, 
                $endTimeFormatted,
                $duration,
                $e->getMessage()
            );
            
            if ($printToConsole) {
                echo $output;
            }
            
            if ($writeToLog) {
                $this->writeToLog($output);
            }
            
            throw $e;
        }
    }
    
    /**
     * @DESC         |初始化日志文件
     */
    private function initLogFile(): void
    {
        $logFile = $this->getLogFilePath();
        $logDir = dirname($logFile);
        
        // 确保日志目录存在
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        
        // 写入事件头部信息
        $header = "\n" . str_repeat("=", 80) . "\n";
        $header .= date('Y-m-d H:i:s') . " - " . __("Event Name: %{1}", [$this->getName()]) . "\n";
        $header .= str_repeat("-", 80) . "\n";
        $header .= sprintf("%-50s %-15s %-15s %s\n", __("Class Name"), __("Start Time"), __("End Time"), __("Duration"));
        $header .= str_repeat("-", 80) . "\n";
        
        file_put_contents($logFile, $header, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * @DESC         |写入日志
     *
     * @param string $message
     */
    private function writeToLog(string $message): void
    {
        $logFile = $this->getLogFilePath();
        file_put_contents($logFile, $message, FILE_APPEND | LOCK_EX);
    }
    
    /**
     * @DESC         |获取日志文件路径
     *
     * @return string
     */
    private function getLogFilePath(): string
    {
        // 获取项目根目录
        $rootPath = dirname(dirname(dirname(dirname(dirname(__DIR__)))));
        return $rootPath . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . 'log' . DIRECTORY_SEPARATOR . 'event.log';
    }
}

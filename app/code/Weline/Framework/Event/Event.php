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

class Event extends \Weline\Framework\DataObject\DataObject
{
    public function __construct(array $data = [])
    {
        if (isset($data['observers'])) {
            foreach ($data['observers'] as $key => $observer) {
                $observer = ObjectManager::getInstance($observer['instance']);
                if ($observer instanceof ObserverInterface) {
                    $data['observers'][$key] = $observer;
                } elseif (DEV) {
                    throw new Core(__('观察者必须继承于：') . ObserverInterface::class);
                } else {
                    $debug = ObjectManager::getInstance(Printing::class);
                    $debug->debug(__('观察者必须继承于：') . ObserverInterface::class);
                }
            }
        }
        parent::__construct($data);
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
                    $eventData[$k] = $value;
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
        
        // 获取配置中的 event.debug 设置
        $eventDebugConfig = Env::getInstance()->getConfig('event.debug');
        
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
                $isWebDebugMode = isset($_GET['event-debug']);
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
        
        foreach ($observers as $observer) {
            if ($needDebug) {
                $this->executeWithDebug($observer, $printToConsole, $needLog);
            } else {
                $observer->execute($this);
            }
        }
        
        if ($printToConsole && !empty($observers)) {
            echo "\n";
        }
    }
    
    /**
     * @DESC         |打印事件头部信息
     */
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

<?php

namespace Weline\Framework\Console\Console\Server\Observer;

use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Event\Event;

class ServerStartObserver implements ObserverInterface
{
    /**
     * 服务器启动事件观察者
     * 
     * @param Event $event
     * @return void
     */
    public function execute(Event $event): void
    {
        $data = $event->getData();
        
        // 记录服务器启动日志
        $logMessage = sprintf(
            '[%s] 服务器启动 - PID: %d, Host: %s, Port: %d, Backend: %s, Force: %s',
            date('Y-m-d H:i:s'),
            $data['pid'],
            $data['host'],
            $data['port'],
            $data['backend'] ? '是' : '否',
            $data['force'] ? '是' : '否'
        );
        
        // 可以在这里添加自定义逻辑，比如：
        // - 发送通知
        // - 记录到数据库
        // - 更新监控状态
        // - 触发其他相关操作
        
        // 示例：写入日志文件
        $logFile = BP . 'var/log/server-events.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}

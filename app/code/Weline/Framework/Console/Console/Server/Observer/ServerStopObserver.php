<?php

namespace Weline\Framework\Console\Console\Server\Observer;

use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Event\Event;

class ServerStopObserver implements ObserverInterface
{
    /**
     * 服务器停止事件观察者
     * 
     * @param Event $event
     * @return void
     */
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        
        // 记录服务器停止日志
        $stoppedPids = $data['stopped_pids'] ?? [];
        $stoppedPidsStr = is_array($stoppedPids) ? implode(', ', $stoppedPids) : '无';
        $logMessage = sprintf(
            '[%s] 服务器停止 - Host: %s, Port: %d, Force: %s, Stopped PIDs: [%s]',
            date('Y-m-d H:i:s'),
            $data['host'],
            $data['port'],
            $data['force'] ? '是' : '否',
            $stoppedPidsStr
        );
        
        // 可以在这里添加自定义逻辑，比如：
        // - 发送通知
        // - 记录到数据库
        // - 更新监控状态
        // - 清理相关资源
        // - 触发其他相关操作
        
        // 示例：写入日志文件
        $logFile = BP . 'var/log/server-events.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        file_put_contents($logFile, $logMessage . PHP_EOL, FILE_APPEND | LOCK_EX);
        
        // 示例：清理临时文件
        $this->cleanupTempFiles();
    }
    
    /**
     * 清理临时文件示例
     */
    private function cleanupTempFiles(): void
    {
        $tempDir = BP . 'var/tmp/';
        if (is_dir($tempDir)) {
            $files = glob($tempDir . 'server_*');
            foreach ($files as $file) {
                if (is_file($file) && (time() - filemtime($file)) > 3600) { // 1小时前的文件
                    unlink($file);
                }
            }
        }
    }
}

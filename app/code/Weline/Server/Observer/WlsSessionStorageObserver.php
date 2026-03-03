<?php

declare(strict_types=1);

/**
 * WLS Session 存储发现 Observer
 *
 * 监听 Session 模块的 storage_resolve 事件，检测 WLS Session Server 是否运行。
 * 通过检查 var/session/wls.discovery 文件判断服务状态，实现 Session 模块与 WLS 的完全解耦。
 *
 * @author Aiweline
 */

namespace Weline\Server\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class WlsSessionStorageObserver implements ObserverInterface
{
    /**
     * 检测 WLS Session Server 并设置存储类型
     *
     * 检查 discovery 文件是否存在，若存在则表示 WLS Session Server 正在运行，
     * 设置 storage_type 为 'wls'，并传递连接配置。
     */
    public function execute(Event &$event): void
    {
        $data = $event->getData();
        
        $basePath = \defined('BP') ? BP . 'var/session/' : '/tmp/wls_session/';
        $discoveryFile = $basePath . 'wls.discovery';
        
        if (!\is_file($discoveryFile)) {
            return;
        }
        
        $content = @\file_get_contents($discoveryFile);
        if ($content === false) {
            return;
        }
        
        $info = \json_decode($content, true);
        if (!\is_array($info) || empty($info['type'])) {
            return;
        }
        
        // 验证 PID 是否仍在运行（可选但推荐）
        if (isset($info['pid'])) {
            $pid = (int)$info['pid'];
            if ($pid > 0 && !$this->isProcessRunning($pid)) {
                // 进程已死，清理过期的 discovery 文件
                @\unlink($discoveryFile);
                return;
            }
        }
        
        $data['storage_type'] = 'wls';
        $data['storage_config'] = [
            'host' => $info['host'] ?? '127.0.0.1',
            'port' => (int)($info['port'] ?? 19970),
        ];
        
        $event->setData($data);
    }
    
    /**
     * 检测进程是否存活
     */
    private function isProcessRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        
        // Windows
        if (\PHP_OS_FAMILY === 'Windows') {
            $output = [];
            @\exec("tasklist /FI \"PID eq {$pid}\" 2>NUL", $output);
            foreach ($output as $line) {
                if (\str_contains($line, (string)$pid)) {
                    return true;
                }
            }
            return false;
        }
        
        // Linux/macOS
        if (\function_exists('posix_kill')) {
            return @\posix_kill($pid, 0);
        }
        
        // Fallback
        return \is_dir("/proc/{$pid}");
    }
}

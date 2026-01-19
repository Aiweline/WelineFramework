<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Hook\Plugin;

use Weline\Framework\App\Env;

/**
 * Setup升级执行前插件
 * 在setup:upgrade执行前清理昨天的记录和过期文件
 */
class SetupUpgradeBeforeExecutePlugin
{
    /**
     * Setup升级执行前的拦截方法
     * 清理昨天的setup执行记录和过期文件
     */
    public function beforeExecute(): void
    {
        try {
            // 清理昨天的锁文件和过期进程文件
            $this->cleanupOldLockFiles();
            
            // 清理昨天的日志文件（如果有）
            $this->cleanupOldLogFiles();
            
            // 清理其他临时文件
            $this->cleanupTempFiles();
        } catch (\Exception $e) {
            // 清理失败不影响主流程，只记录错误
            Env::log_error('hook', '清理setup历史记录失败: ' . $e->getMessage());
        }
    }
    
    /**
     * 清理过期的锁文件
     */
    private function cleanupOldLockFiles(): void
    {
        $processDir = BP . 'var' . DS . 'process';
        if (!is_dir($processDir)) {
            return;
        }
        
        $lockFile = $processDir . DS . 'setup_upgrade.lock';
        $yesterday = strtotime('-1 day');
        
        // 检查锁文件是否存在
        if (file_exists($lockFile)) {
            $lockInfo = $this->readLockInfo($lockFile);
            $shouldDelete = false;
            
            // 如果锁文件信息存在，检查进程是否还在运行
            if ($lockInfo !== null && isset($lockInfo['pid'])) {
                $pid = (int)$lockInfo['pid'];
                
                // 检查进程是否还在运行
                if (!$this->isProcessRunning($pid)) {
                    // 进程已不存在，删除锁文件
                    $shouldDelete = true;
                } elseif (isset($lockInfo['time'])) {
                    // 检查锁文件创建时间，如果是昨天的，也删除（可能是僵尸进程）
                    $lockTime = strtotime($lockInfo['time']);
                    if ($lockTime < $yesterday) {
                        $shouldDelete = true;
                    }
                }
            } else {
                // 锁文件信息无效，检查文件修改时间
                $fileTime = @filemtime($lockFile);
                if ($fileTime !== false && $fileTime < $yesterday) {
                    $shouldDelete = true;
                }
            }
            
            if ($shouldDelete) {
                @unlink($lockFile);
            }
        }
        
        // 清理过期的PID文件（昨天的）
        $pidDir = $processDir . DS . 'pid';
        if (is_dir($pidDir)) {
            $files = glob($pidDir . DS . '*.pid');
            if ($files === false) {
                return;
            }
            
            foreach ($files as $file) {
                $fileTime = @filemtime($file);
                if ($fileTime !== false && $fileTime < $yesterday) {
                    // 检查PID文件对应的进程是否还在运行
                    $pid = (int)basename($file, '.pid');
                    if ($pid > 0 && !$this->isProcessRunning($pid)) {
                        @unlink($file);
                    }
                }
            }
        }
    }
    
    /**
     * 清理昨天的日志文件
     */
    private function cleanupOldLogFiles(): void
    {
        $logDir = BP . 'var' . DS . 'log';
        if (!is_dir($logDir)) {
            return;
        }
        
        $yesterday = strtotime('-1 day');
        
        // 清理setup相关的日志文件
        $patterns = [
            'setup_upgrade*.log',
            'setup*.log',
        ];
        
        foreach ($patterns as $pattern) {
            $files = glob($logDir . DS . $pattern);
            if ($files === false) {
                continue;
            }
            
            foreach ($files as $file) {
                $fileTime = @filemtime($file);
                if ($fileTime !== false && $fileTime < $yesterday) {
                    // 只删除昨天的日志文件，保留更早的（可能用于审计）
                    // 如果需要保留更长时间，可以调整这里的逻辑
                    @unlink($file);
                }
            }
        }
    }
    
    /**
     * 清理临时文件
     */
    private function cleanupTempFiles(): void
    {
        $tempDir = BP . 'var' . DS . 'tmp';
        if (!is_dir($tempDir)) {
            return;
        }
        
        $yesterday = strtotime('-1 day');
        
        // 清理setup相关的临时文件
        $patterns = [
            'setup_upgrade_*.tmp',
            'setup_*.tmp',
        ];
        
        foreach ($patterns as $pattern) {
            $files = glob($tempDir . DS . $pattern);
            if ($files === false) {
                continue;
            }
            
            foreach ($files as $file) {
                $fileTime = @filemtime($file);
                if ($fileTime !== false && $fileTime < $yesterday) {
                    @unlink($file);
                }
            }
        }
    }
    
    /**
     * 读取锁文件信息
     * 
     * @param string $lockFile 锁文件路径
     * @return array|null
     */
    private function readLockInfo(string $lockFile): ?array
    {
        if (!file_exists($lockFile)) {
            return null;
        }
        
        $content = @file_get_contents($lockFile);
        if ($content === false || empty(trim($content))) {
            return null;
        }
        
        $info = @json_decode($content, true);
        return is_array($info) ? $info : null;
    }
    
    /**
     * 检查进程是否还在运行
     * 
     * @param int $pid 进程ID
     * @return bool
     */
    private function isProcessRunning(int $pid): bool
    {
        if (IS_WIN) {
            // Windows 系统：使用 tasklist 命令检查进程
            $output = [];
            $returnVar = 0;
            exec("tasklist /FI \"PID eq $pid\" 2>NUL", $output, $returnVar);
            if ($returnVar === 0 && !empty($output)) {
                // 检查输出中是否包含进程ID
                foreach ($output as $line) {
                    if (strpos($line, (string)$pid) !== false) {
                        return true;
                    }
                }
            }
            return false;
        } else {
            // Linux/Unix 系统：使用 kill -0 检查进程
            return posix_kill($pid, 0);
        }
    }
}

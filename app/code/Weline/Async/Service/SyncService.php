<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Async\Service;

use Weline\Async\Model\SyncHost;
use Weline\Async\Model\SyncMapping;
use Weline\Framework\Manager\ObjectManager;

/**
 * 同步服务
 * 
 * 负责执行rsync同步操作
 * 
 * @package Weline_Async
 */
class SyncService
{
    /**
     * 执行rsync同步
     * 
     * @param SyncMapping $mapping 目录映射
     * @param string|null $filePath 特定文件路径（可选，用于增量同步）
     * @return array 同步结果
     */
    public function sync(SyncMapping $mapping, ?string $filePath = null): array
    {
        $host = $mapping->getHost();
        if (!$host) {
            return [
                'success' => false,
                'message' => '主机配置不存在'
            ];
        }

        $localPath = $mapping->getData(SyncMapping::fields_LOCAL_PATH);
        $remotePath = $mapping->getData(SyncMapping::fields_REMOTE_PATH);
        
        if (empty($localPath) || empty($remotePath)) {
            return [
                'success' => false,
                'message' => '本地路径或远程路径为空'
            ];
        }

        // 构建rsync命令
        $rsyncCmd = $this->buildRsyncCommand($host, $localPath, $remotePath, $mapping, $filePath);
        
        // 执行rsync命令
        $output = [];
        $returnVar = 0;
        exec($rsyncCmd . ' 2>&1', $output, $returnVar);
        
        if ($returnVar === 0) {
            return [
                'success' => true,
                'message' => '同步成功',
                'output' => implode("\n", $output)
            ];
        } else {
            return [
                'success' => false,
                'message' => '同步失败',
                'output' => implode("\n", $output),
                'error_code' => $returnVar
            ];
        }
    }

    /**
     * 构建rsync命令
     * 
     * @param SyncHost $host 主机配置
     * @param string $localPath 本地路径
     * @param string $remotePath 远程路径
     * @param SyncMapping $mapping 映射配置
     * @param string|null $filePath 特定文件路径
     * @return string rsync命令
     */
    private function buildRsyncCommand(
        SyncHost $host,
        string $localPath,
        string $remotePath,
        SyncMapping $mapping,
        ?string $filePath = null
    ): string {
        $hostAddress = $host->getData(SyncHost::fields_HOST);
        $port = $host->getData(SyncHost::fields_PORT) ?: 22;
        $user = $host->getData(SyncHost::fields_USER);
        
        // 构建SSH选项
        $sshOptions = $this->buildSshOptions($host);
        
        // 构建rsync选项
        $rsyncOptions = [
            '-avz',  // archive, verbose, compress
            '--delete',  // 删除目标中源没有的文件
            '--progress',  // 显示进度
        ];
        
        // 添加排除模式
        $excludePatterns = $mapping->getExcludePatternsArray();
        foreach ($excludePatterns as $pattern) {
            $rsyncOptions[] = "--exclude=" . escapeshellarg($pattern);
        }
        
        // 如果指定了文件路径，只同步该文件
        if ($filePath) {
            $localPath = rtrim($localPath, '/\\') . '/' . ltrim($filePath, '/\\');
        }
        
        // 确保路径以/结尾（rsync目录同步要求）
        if (is_dir($localPath)) {
            $localPath = rtrim($localPath, '/\\') . '/';
        }
        $remotePath = rtrim($remotePath, '/\\') . '/';
        
        // 构建完整命令
        $cmd = sprintf(
            'rsync %s -e "ssh %s -p %d" %s %s@%s:%s',
            implode(' ', $rsyncOptions),
            $sshOptions,
            $port,
            escapeshellarg($localPath),
            escapeshellarg($user),
            escapeshellarg($hostAddress),
            escapeshellarg($remotePath)
        );
        
        return $cmd;
    }

    /**
     * 构建SSH选项
     * 
     * @param SyncHost $host 主机配置
     * @return string SSH选项字符串
     */
    private function buildSshOptions(SyncHost $host): string
    {
        $options = [];
        
        // 如果使用密钥认证：优先使用密钥内容，其次使用路径
        $keyPath = '';
        $keyContent = $host->getDecryptedKeyContent();
        if (!empty($keyContent)) {
            // 创建临时密钥文件
            $keyDir = BP . DS . 'var' . DS . 'async' . DS . 'keys';
            if (!is_dir($keyDir)) {
                mkdir($keyDir, 0700, true);
            }
            $keyFile = $keyDir . DS . 'host_' . $host->getId() . '_key_' . time() . '.pem';
            file_put_contents($keyFile, $keyContent);
            chmod($keyFile, 0600); // 设置权限为仅所有者可读写
            $keyPath = $keyFile;
        } elseif ($host->getData(SyncHost::fields_KEY_PATH) && file_exists($host->getData(SyncHost::fields_KEY_PATH))) {
            // 兼容旧数据：如果只有路径，使用路径
            $keyPath = $host->getData(SyncHost::fields_KEY_PATH);
        }
        
        if (!empty($keyPath) && file_exists($keyPath)) {
            $options[] = '-i ' . escapeshellarg($keyPath);
        }
        
        // 禁用主机密钥检查（可选，生产环境建议启用）
        $options[] = '-o StrictHostKeyChecking=no';
        $options[] = '-o UserKnownHostsFile=/dev/null';
        
        return implode(' ', $options);
    }

    /**
     * 测试SSH连接
     * 
     * @param SyncHost $host 主机配置
     * @return array 测试结果
     */
    public function testSshConnection(SyncHost $host): array
    {
        $hostAddress = $host->getData(SyncHost::fields_HOST);
        $port = $host->getData(SyncHost::fields_PORT) ?: 22;
        $user = $host->getData(SyncHost::fields_USER);
        $password = $host->getDecryptedPassword();
        $keyContent = $host->getDecryptedKeyContent();
        
        // 构建SSH选项
        $sshOptions = [];
        $keyFile = null;
        
        if (!empty($keyContent)) {
            // 清理密钥内容
            $keyContent = trim($keyContent);
            
            // 创建临时密钥文件
            $keyDir = BP . DS . 'var' . DS . 'async' . DS . 'keys';
            if (!is_dir($keyDir)) {
                mkdir($keyDir, 0700, true);
            }
            $keyFile = $keyDir . DS . 'test_' . $host->getId() . '_' . time() . '.pem';
            
            // 确保密钥内容以换行符结尾
            if (!preg_match('/\n$/', $keyContent)) {
                $keyContent .= "\n";
            }
            
            file_put_contents($keyFile, $keyContent);
            chmod($keyFile, 0600);
            $sshOptions[] = '-i ' . escapeshellarg($keyFile);
        }
        
        $sshOptions[] = '-o StrictHostKeyChecking=no';
        $sshOptions[] = '-o UserKnownHostsFile=/dev/null';
        $sshOptions[] = '-o ConnectTimeout=10';
        
        // 构建SSH测试命令
        $cmd = sprintf(
            'ssh %s -p %d %s@%s "echo test" 2>&1',
            implode(' ', $sshOptions),
            $port,
            escapeshellarg($user),
            escapeshellarg($hostAddress)
        );
        
        // 如果使用密码认证
        if (!empty($password) && empty($keyContent)) {
            $passwordSshOptions = [];
            $passwordSshOptions[] = '-o StrictHostKeyChecking=no';
            $passwordSshOptions[] = '-o UserKnownHostsFile=/dev/null';
            $passwordSshOptions[] = '-o ConnectTimeout=10';
            
            $cmd = sprintf(
                'sshpass -p %s ssh %s -p %d %s@%s "echo test" 2>&1',
                escapeshellarg($password),
                implode(' ', $passwordSshOptions),
                $port,
                escapeshellarg($user),
                escapeshellarg($hostAddress)
            );
        }
        
        $output = [];
        $returnVar = 0;
        exec($cmd, $output, $returnVar);
        
        // 清理临时密钥文件
        if (!empty($keyFile) && file_exists($keyFile)) {
            @unlink($keyFile);
        }
        
        if ($returnVar === 0) {
            return [
                'success' => true,
                'message' => 'SSH连接成功',
                'output' => implode("\n", $output),
                'command' => substr($cmd, 0, 200) // 记录命令（前200字符）
            ];
        } else {
            $errorMsg = implode("\n", $output);
            return [
                'success' => false,
                'message' => 'SSH连接失败',
                'output' => $errorMsg,
                'error_code' => $returnVar,
                'command' => substr($cmd, 0, 200) // 记录命令（前200字符）
            ];
        }
    }
}

<?php

declare(strict_types=1);

/*
 * 本文件由 秋枫雁飞 编写，所有解释权归Aiweline所有。
 * 邮箱：aiweline@qq.com
 * 网址：aiweline.com
 * 论坛：https://bbs.aiweline.com
 */

namespace Weline\Async\Console\Command;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Manager\ObjectManager;
use Weline\Async\Model\SyncHost;
use Weline\Async\Service\SyncService;

class TestSsh extends CommandAbstract
{
    private SyncHost $syncHost;
    private SyncService $syncService;

    public function __construct()
    {
        $this->syncHost = ObjectManager::getInstance(SyncHost::class);
        $this->syncService = ObjectManager::getInstance(SyncService::class);
    }

    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = []): void
    {
        $hostId = $args[0] ?? null;
        
        if (empty($hostId)) {
            $this->printer->error('请提供主机ID');
            $this->printer->print('用法: php bin/w async:test-ssh <host_id>');
            return;
        }

        $host = $this->syncHost->clear()->load($hostId);
        if (!$host->getId()) {
            $this->printer->error("主机ID {$hostId} 不存在");
            return;
        }

        $this->printer->print("正在测试SSH连接...");
        $this->printer->print("主机: {$host->getData(SyncHost::fields_NAME)} ({$host->getData(SyncHost::fields_HOST)})");
        $this->printer->print("用户: {$host->getData(SyncHost::fields_USER)}");
            $port = $host->getData(SyncHost::fields_PORT);
            $this->printer->print("端口: " . ($port ?: 22));
        $this->printer->print("");

        // 检查密钥
        $keyContent = $host->getDecryptedKeyContent();
        if (!empty($keyContent)) {
            $this->printer->print("认证方式: SSH密钥");
            // 验证密钥格式
            if (strpos($keyContent, '-----BEGIN') !== false && strpos($keyContent, '-----END') !== false) {
                $this->printer->print("密钥格式: 正确");
            } else {
                $this->printer->warning("密钥格式: 可能不正确（缺少BEGIN/END标记）");
            }
        } else {
            $password = $host->getDecryptedPassword();
            if (!empty($password)) {
                $this->printer->print("认证方式: 密码");
            } else {
                $this->printer->error("未配置密钥或密码");
                return;
            }
        }

        $this->printer->print("");

        // 测试连接
        $result = $this->syncService->testSshConnection($host);

        if ($result['success']) {
            $this->printer->success("✓ SSH连接成功！");
            $this->printer->print("输出: " . $result['output']);
        } else {
            $this->printer->error("✗ SSH连接失败！");
            $this->printer->print("错误代码: " . ($result['error_code'] ?? 'N/A'));
            $this->printer->print("错误信息:");
            $this->printer->print($result['output']);
            
            // 提供调试建议
            $this->printer->print("");
            $this->printer->print("调试建议:");
            $this->printer->print("1. 检查密钥格式是否正确（应包含 -----BEGIN 和 -----END 标记）");
            $this->printer->print("2. 检查服务器上的 ~/.ssh/authorized_keys 是否包含对应的公钥");
            $this->printer->print("3. 检查服务器上的文件权限:");
            $this->printer->print("   - ~/.ssh 目录权限应为 700");
            $this->printer->print("   - ~/.ssh/authorized_keys 文件权限应为 600");
            $sshPort = $host->getData(SyncHost::fields_PORT) ?: 22;
            $sshUser = $host->getData(SyncHost::fields_USER);
            $sshHost = $host->getData(SyncHost::fields_HOST);
            $this->printer->print("4. 尝试手动SSH连接: ssh -p {$sshPort} {$sshUser}@{$sshHost}");
        }
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return '测试SSH连接';
    }

    /**
     * @inheritDoc
     */
    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'async:test-ssh',
            $this->tip(),
            ['host_id' => '主机ID'],
            [],
            [
                '测试主机ID为1的SSH连接' => 'php bin/w async:test-ssh 1',
            ]
        );
    }
}

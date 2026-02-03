<?php
declare(strict_types=1);

/**
 * Weline Server - 重启命令
 * 
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Console\Server;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;

/**
 * server:restart - 重启常驻内存服务器
 */
class Restart extends CommandAbstract
{
    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        $this->printer->note(__('正在重启 Weline Server...'));
        
        // 先停止
        $stopCommand = ObjectManager::getInstance(Stop::class);
        $stopCommand->execute($args, $data);
        
        // 等待进程完全停止
        sleep(2);
        
        // 设置守护进程模式
        $args['d'] = true;
        
        // 再启动
        $startCommand = ObjectManager::getInstance(Start::class);
        $startCommand->execute($args, $data);
    }
    
    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('重启 Weline 常驻内存 HTTP 服务器');
    }
    
    /**
     * @inheritDoc
     */
    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:restart',
            __('重启 Weline Server（先停止再启动）'),
            [
                '-h, --host <ip>' => __('监听地址（默认：0.0.0.0）'),
                '-p, --port <port>' => __('监听端口（默认：8080）'),
                '-c, --count <n>' => __('Worker 进程数（默认：4）'),
                '--help' => __('显示帮助信息'),
            ],
            [],
            [
                __('重启服务器') => 'php bin/w server:restart',
                __('重启并更改端口') => 'php bin/w server:restart -p 9000',
            ]
        );
    }
}

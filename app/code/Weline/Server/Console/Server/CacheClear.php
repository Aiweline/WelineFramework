<?php
declare(strict_types=1);

/**
 * Weline Server - 清除路由缓存命令
 *
 * 用于清除 Dispatcher 的路由缓存，解决重定向循环等缓存问题
 *
 * @author Aiweline
 * @email aiweline@qq.com
 */

namespace Weline\Server\Console\Server;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Manager\ObjectManager;
use Weline\Server\Service\Control\IpcControlGateway;
use Weline\Server\Service\ServerInstanceManager;

/**
 * Class CacheClear
 *
 * server:cache:clear - 清除 WLS Dispatcher 路由缓存
 */
class CacheClear extends CommandAbstract
{
    /**
     * @inheritDoc
     */
    public function execute(array $args = [], array $data = [])
    {
        // 解析实例名参数
        $positionalArgs = [];
        foreach ($args as $key => $arg) {
            if (is_int($key) && !str_starts_with((string)$arg, '-')) {
                $positionalArgs[] = $arg;
            }
        }
        array_shift($positionalArgs); // 移除命令名
        $instanceName = $positionalArgs[0] ?? 'default';

        $this->printer->setup(__('清除 WLS 路由缓存...'));

        /** @var ServerInstanceManager $instanceManager */
        $instanceManager = ObjectManager::getInstance(ServerInstanceManager::class);

        // 获取实例信息
        $instance = $instanceManager->getInstanceInfo($instanceName);
        if (!$instance) {
            $this->printer->error(__('实例不存在') . ': ' . $instanceName);
            return;
        }

        // 检查实例是否运行
        if (!$instance->isMasterRunning()) {
            $this->printer->warning(__('实例未运行') . ': ' . $instanceName);
            return;
        }

        $this->printer->note(__('发送路由缓存清除命令'));
        $result = (new IpcControlGateway())->routingCacheClear($instanceName);
        if (!empty($result['success'])) {
            $this->printer->success(__('路由缓存清除命令已被 Master 接收'));
            $this->printer->note((string)($result['message'] ?? ''));
            $this->printer->note(__('状态：%{1}', [(string)($result['status'] ?? 'accepted')]));
            return;
        }

        $this->printer->error(__('清除缓存失败') . ': ' . (string)($result['message'] ?? 'unknown'));
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('清除 WLS Dispatcher 路由缓存');
    }
}

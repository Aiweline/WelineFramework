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
use Weline\Server\IPC\ControlMessage;
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

        // 发送清除缓存命令到 Master
        try {
            $host = '127.0.0.1';
            $errno = 0;
            $errstr = '';
            $conn = @\stream_socket_client("tcp://{$host}:{$instance->controlPort}", $errno, $errstr, 5);

            if (!$conn) {
                $this->printer->error(__('连接控制端口失败') . ": {$errstr} (errno:{$errno})");
                return;
            }

            // 发送清除缓存命令
            $message = ControlMessage::command(ControlMessage::ACTION_ROUTING_CACHE_CLEAR);
            $this->printer->note('发送消息: ' . trim($message));
            \fwrite($conn, $message);

            // 等待响应
            $response = \fgets($conn);
            \fclose($conn);

            if ($response) {
                $data = \json_decode(\trim($response), true);
                if ($data && isset($data['success']) && $data['success']) {
                    $this->printer->success(__('路由缓存已清除'));
                    if (isset($data['message'])) {
                        $this->printer->note($data['message']);
                    }
                } else {
                    $this->printer->error(__('清除缓存失败') . ': ' . ($data['message'] ?? 'unknown'));
                }
            } else {
                $this->printer->warning(__('未收到响应'));
            }
        } catch (\Exception $e) {
            $this->printer->error(__('连接控制端口失败') . ': ' . $e->getMessage());
        }
    }

    /**
     * @inheritDoc
     */
    public function tip(): string
    {
        return __('清除 WLS Dispatcher 路由缓存');
    }
}

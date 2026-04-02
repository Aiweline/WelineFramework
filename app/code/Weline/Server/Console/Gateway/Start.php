<?php
declare(strict_types=1);

namespace Weline\Server\Console\Gateway;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Server\Service\WlsGateway;
use Weline\Framework\Console\CommandInterface;

/**
 * WLS Gateway 启动命令
 *
 * 用法：
 *   php bin/w gateway:start
 *   php bin/w gateway:start --config=custom.php
 */
class Start implements CommandInterface
{
    private Printing $printer;

    public function __construct(Printing $printer)
    {
        $this->printer = $printer;
    }

    public function execute(array $args = [], array $data = []): mixed
    {
        $this->printer->setup(__('WLS Gateway - 多项目统一入口'));
        $this->printer->note('');

        // 加载配置
        $configFile = $args['config'] ?? null;
        $config = $this->loadConfig($configFile);

        if (!$config) {
            $this->printer->error(__('未找到 Gateway 配置'));
            $this->printer->note(__('请在 app/etc/env.php 中配置 wls.gateway'));
            $this->printer->note(__('或使用 --config 参数指定配置文件'));
            return null;
        }

        // 创建 Gateway
        $gateway = new WlsGateway();
        $gateway->loadConfig($config);

        // 启动
        try {
            $gateway->start();
        } catch (\Throwable $e) {
            $this->printer->error(__('Gateway 启动失败: %{1}', [$e->getMessage()]));
            return null;
        }

        return null;
    }

    /**
     * 加载配置
     */
    private function loadConfig(?string $configFile): ?array
    {
        // 从指定文件加载
        if ($configFile && is_file($configFile)) {
            return include $configFile;
        }

        // 从 env.php 加载
        $envConfig = Env::getInstance()->getConfig();
        if (isset($envConfig['wls']['gateway'])) {
            return $envConfig['wls']['gateway'];
        }

        // 自动发现配置：扫描所有运行中的 WLS 实例
        return $this->autoDiscoverConfig();
    }

    /**
     * 自动发现配置
     *
     * 扫描 var/server/instances/ 目录，自动生成路由规则
     */
    private function autoDiscoverConfig(): ?array
    {
        $instanceDir = Env::VAR_DIR . 'server' . DIRECTORY_SEPARATOR . 'instances' . DIRECTORY_SEPARATOR;
        if (!is_dir($instanceDir)) {
            return null;
        }

        $routes = [];
        $defaultBackend = null;

        $files = glob($instanceDir . '*.json');
        foreach ($files as $file) {
            $instance = json_decode(file_get_contents($file), true);
            if (!$instance) {
                continue;
            }

            $host = $instance['host'] ?? '127.0.0.1';
            $port = $instance['port'] ?? 443;

            // 跳过已经监听在 443 的实例
            if ($port === 443) {
                continue;
            }

            // 如果有域名，添加路由
            if ($host !== '127.0.0.1' && $host !== 'localhost') {
                $routes[$host] = [
                    'host' => '127.0.0.1',
                    'port' => $port,
                    'ssl' => $instance['ssl_enabled'] ?? true,
                ];
            }

            // 第一个实例作为默认后端
            if (!$defaultBackend) {
                $defaultBackend = [
                    'host' => '127.0.0.1',
                    'port' => $port,
                    'ssl' => $instance['ssl_enabled'] ?? true,
                ];
            }
        }

        if (empty($routes) && !$defaultBackend) {
            return null;
        }

        $this->printer->note(__('自动发现 %{1} 个项目实例', [count($routes)]));

        return [
            'listen' => '0.0.0.0:443',
            'routes' => $routes,
            'default' => $defaultBackend,
        ];
    }

    public function tip(): string
    {
        return __('启动 WLS Gateway 统一入口反向代理');
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'gateway:start [--config=<file>]',
            $this->tip(),
            [
                '--config' => __('指定配置文件路径（可选）'),
            ],
            [
                __('示例:') => [
                    'php bin/w gateway:start' => __('使用自动发现模式启动'),
                    'php bin/w gateway:start --config=app/etc/gateway.php' => __('使用指定配置文件启动'),
                ],
            ],
            []
        );
    }
}

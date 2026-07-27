<?php
declare(strict_types=1);

namespace Weline\Server\Console\Gateway;

use Weline\Framework\App\Env;
use Weline\Framework\Output\Cli\Printing;
use Weline\Framework\Console\CommandInterface;

/**
 * Retired compatibility command. Execution always fails closed.
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
        $this->printer->error('gateway:start: ' . __('Nginx 是唯一公网边缘，不能跳过其启动。'));

        return 1;
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
        return __('Nginx 是唯一公网边缘，不能跳过其启动。');
    }

    public function help(): array|string
    {
        return \Weline\Framework\Console\CommandHelper::formatHelp(
            'gateway:start',
            $this->tip(),
            [],
            [],
            []
        );
    }
}

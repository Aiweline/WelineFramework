<?php
declare(strict_types=1);

namespace Weline\Server\Console\Server\Shared;

use Weline\Framework\App\Env;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\SharedStateServiceManager;

class Start extends CommandAbstract
{
    public function execute(array $args = [], array $data = [])
    {
        $manager = new SharedStateServiceManager();
        $envConfig = Env::getInstance()->getConfig();
        if (!\is_array($envConfig)) {
            $envConfig = [];
        }

        $session = $manager->start(ControlMessage::ROLE_SESSION_SERVER, [], $envConfig, 'shared-admin');
        $this->renderRuntime('Session Server', $session);

        if ($this->isMemoryEnabled($envConfig)) {
            $memory = $manager->start(ControlMessage::ROLE_MEMORY_SERVER, [], $envConfig, 'shared-admin');
            $this->renderRuntime('Memory Service', $memory);
        } else {
            $this->printer->note(__('Memory Service disabled by configuration.'));
        }
    }

    public function tip(): string
    {
        return __('启动全局共享 Session/Memory 服务');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:shared:start',
            __('启动或重启全局共享 Session/Memory 服务'),
            [
                '--help' => __('显示帮助信息'),
            ],
            [
                __('行为') => __('共享服务独立于实例 Master；此命令只管理全局 Session/Memory。'),
            ],
            [
                __('启动共享服务') => 'php bin/w server:shared:start',
            ]
        );
    }

    /**
     * @param array<string, mixed> $runtime
     */
    private function renderRuntime(string $label, array $runtime): void
    {
        $this->printer->success(
            __(
                '%{1} ready: %{2}:%{3} (pid=%{4}, token=%{5})',
                [
                    $label,
                    (string) ($runtime['host'] ?? '127.0.0.1'),
                    (int) ($runtime['port'] ?? 0),
                    (int) ($runtime['pid'] ?? 0),
                    (string) ($runtime['token_file_name'] ?? ''),
                ]
            )
        );
    }

    /**
     * @param array<string, mixed> $envConfig
     */
    private function isMemoryEnabled(array $envConfig): bool
    {
        $memory = \is_array(($envConfig['wls'] ?? [])['memory_service'] ?? null)
            ? $envConfig['wls']['memory_service']
            : [];

        return (bool) ($memory['enabled'] ?? true);
    }
}

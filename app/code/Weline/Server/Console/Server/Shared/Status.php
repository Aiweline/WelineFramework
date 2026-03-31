<?php
declare(strict_types=1);

namespace Weline\Server\Console\Server\Shared;

use Weline\Framework\App\Env;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\SharedStateServiceManager;

class Status extends CommandAbstract
{
    public function execute(array $args = [], array $data = [])
    {
        $manager = new SharedStateServiceManager();
        $envConfig = Env::getInstance()->getConfig();
        if (!\is_array($envConfig)) {
            $envConfig = [];
        }

        $this->renderStatus('Session Server', $manager->status(ControlMessage::ROLE_SESSION_SERVER, [], $envConfig));

        if ($this->isMemoryEnabled($envConfig)) {
            $this->renderStatus('Memory Service', $manager->status(ControlMessage::ROLE_MEMORY_SERVER, [], $envConfig));
        } else {
            $this->printer->note(__('Memory Service disabled by configuration.'));
        }
    }

    public function tip(): string
    {
        return __('查看全局共享 Session/Memory 服务状态');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:shared:status',
            __('查看全局共享 Session/Memory 服务状态'),
            [
                '--help' => __('显示帮助信息'),
            ],
            [],
            [
                __('查看共享服务状态') => 'php bin/w server:shared:status',
            ]
        );
    }

    /**
     * @param array<string, mixed> $status
     */
    private function renderStatus(string $label, array $status): void
    {
        $healthy = (bool) ($status['healthy'] ?? false);
        $printer = $healthy ? 'success' : 'warning';
        $this->printer->$printer(
            __(
                '%{1}: %{2} %{3}:%{4} (pid=%{5}, token=%{6})',
                [
                    $label,
                    $healthy ? 'healthy' : 'down',
                    (string) ($status['host'] ?? '127.0.0.1'),
                    (int) ($status['port'] ?? 0),
                    (int) ($status['pid'] ?? 0),
                    (string) ($status['token_file_name'] ?? ''),
                ]
            )
        );

        $message = \trim((string) ($status['message'] ?? ''));
        if ($message !== '') {
            $this->printer->note(__('  %{1}', [$message]));
        }
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

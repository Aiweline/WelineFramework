<?php
declare(strict_types=1);

namespace Weline\Server\Console\Server\Shared;

use Weline\Framework\App\Env;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\SharedStateServiceManager;

class Stop extends CommandAbstract
{
    public function execute(array $args = [], array $data = [])
    {
        $manager = $this->createManager();
        $envConfig = Env::getInstance()->getConfig();
        if (!\is_array($envConfig)) {
            $envConfig = [];
        }

        $failed = false;
        $sessionStopped = $manager->stop(ControlMessage::ROLE_SESSION_SERVER, [], $envConfig);
        if ($sessionStopped) {
            $this->printer->success(__('Session Server stopped'));
        } else {
            $failed = true;
            $this->printer->warning(
                __('Session Server stop was not confirmed; runtime identity was retained for retry/repair.')
            );
        }

        if ($this->isMemoryEnabled($envConfig)) {
            $memoryStopped = $manager->stop(ControlMessage::ROLE_MEMORY_SERVER, [], $envConfig);
            if ($memoryStopped) {
                $this->printer->success(__('Memory Service stopped'));
            } else {
                $failed = true;
                $this->printer->warning(
                    __('Memory Service stop was not confirmed; runtime identity was retained for retry/repair.')
                );
            }
        } else {
            $this->printer->note(__('Memory Service disabled by configuration.'));
        }

        return $failed ? 1 : 0;
    }

    protected function createManager(): SharedStateServiceManager
    {
        return new SharedStateServiceManager();
    }

    public function tip(): string
    {
        return __('停止全局共享 Session/Memory 服务');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'server:shared:stop',
            __('停止全局共享 Session/Memory 服务'),
            [
                '--help' => __('显示帮助信息'),
            ],
            [],
            [
                __('停止共享服务') => 'php bin/w server:shared:stop',
            ]
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

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
        $sessionReport = $manager->stopWithReport(ControlMessage::ROLE_SESSION_SERVER, [], $envConfig);
        if ((bool)($sessionReport['stopped'] ?? false)) {
            $this->printer->success(__('Session Server stopped'));
        } else {
            $failed = true;
            $this->printer->warning($this->formatUnconfirmedStop('Session Server', $sessionReport));
        }

        if ($this->isMemoryEnabled($envConfig)) {
            $memoryReport = $manager->stopWithReport(ControlMessage::ROLE_MEMORY_SERVER, [], $envConfig);
            if ((bool)($memoryReport['stopped'] ?? false)) {
                $this->printer->success(__('Memory Service stopped'));
            } else {
                $failed = true;
                $this->printer->warning($this->formatUnconfirmedStop('Memory Service', $memoryReport));
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

    /**
     * @param array{reason?:string,host?:string,port?:int,pid?:int} $report
     */
    private function formatUnconfirmedStop(string $label, array $report): string
    {
        $reason = \trim((string)($report['reason'] ?? 'unconfirmed'));
        $host = \trim((string)($report['host'] ?? ''));
        $port = (int)($report['port'] ?? 0);
        $pid = (int)($report['pid'] ?? 0);
        $details = [];
        if ($reason !== '') {
            $details[] = 'reason=' . $reason;
        }
        if ($host !== '' && $port > 0) {
            $details[] = 'endpoint=' . $host . ':' . $port;
        }
        if ($pid > 0) {
            $details[] = 'pid=' . $pid;
        }

        return (string) __(
            '%{1} stop was not confirmed; runtime identity was retained for retry/repair%{2}.',
            [
                $label,
                $details !== [] ? ' (' . \implode(', ', $details) . ')' : '',
            ],
        );
    }
}

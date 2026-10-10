<?php
declare(strict_types=1);

namespace Weline\Server\Observer;

use Weline\Framework\App\Env;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Server\IPC\ControlMessage;
use Weline\Server\Service\Control\BroadcastControlDispatchService;

class CliCommandExecutedObserver implements ObserverInterface
{
    public const RELOAD_TYPE_CODE = 'code';
    public const RELOAD_TYPE_CACHE = 'cache';

    private const SKIP_RELOAD_COMMANDS = ['phpunit:'];

    private const DEFAULT_RELOAD_PREFIXES = [
        'code' => ['setup:', 'command:'],
        'cache' => ['cache:'],
    ];

    public function execute(Event &$event): void
    {
        $command = (string)($event->getData('command') ?? '');
        if ($command === '') {
            return;
        }

        foreach (self::SKIP_RELOAD_COMMANDS as $skipPrefix) {
            if (\str_starts_with($command, $skipPrefix)) {
                return;
            }
        }

        $reloadType = $this->resolveReloadType($command);
        if ($reloadType === null) {
            return;
        }

        // CacheFlushedObserver will notify WLS after the flush actually happens.
        // Skipping cache commands here avoids duplicate cache_clear dispatches.
        if ($reloadType === self::RELOAD_TYPE_CACHE) {
            return;
        }

        /** @var Printing $printer */
        $printer = ObjectManager::getInstance(Printing::class);
        $result = $this->getDispatchService()->reloadAsync(null, ControlMessage::RELOAD_TYPE_CODE);

        $message = (string)__('WLS 通知：%{1}', [$result['message']]);
        if (!empty($result['success'])) {
            $printer->note($message, '', Printing::SUCCESS);
        } else {
            $printer->note($message, '', Printing::DEEP_ORANGE);
        }

        // setup:upgrade 等在命令内先关维护，本观察者再异步 reload。
        // Direct 拓扑下 reload/补位可能留下粘性 Worker 门禁；若框架标志已是关闭，再推一次 disable 愈合。
        if ($reloadType === self::RELOAD_TYPE_CODE && \str_starts_with($command, 'setup:')) {
            $this->reconcileMaintenanceOffAfterSetupReload($printer);
        }
    }

    private function reconcileMaintenanceOffAfterSetupReload(Printing $printer): void
    {
        $enabled = (bool)Env::getInstance()->getConfig('system.maintenance', false);
        if ($enabled) {
            return;
        }

        try {
            $result = $this->getDispatchService()->setMaintenanceMode(false);
            if (($result['attempted'] ?? []) === []) {
                return;
            }
            if (!empty($result['success'])) {
                $printer->note(
                    (string)__('WLS 维护门禁已在代码重载后再次确认关闭：%{1}', [$result['message'] ?? 'ok']),
                    '',
                    Printing::SUCCESS
                );
                return;
            }
            $printer->warning(
                (string)__('WLS 维护门禁在代码重载后确认关闭未完全成功：%{1}', [$result['message'] ?? 'unknown'])
            );
        } catch (\Throwable $throwable) {
            $printer->warning(
                (string)__('WLS 维护门禁在代码重载后确认关闭失败：%{1}', [$throwable->getMessage()])
            );
        }
    }

    public static function triggerReload(string $type = self::RELOAD_TYPE_CODE): void
    {
        $dispatchService = ObjectManager::getInstance(BroadcastControlDispatchService::class);
        if ($type === self::RELOAD_TYPE_CACHE) {
            $dispatchService->cacheClear();
            return;
        }

        $dispatchService->reloadAsync(null, ControlMessage::RELOAD_TYPE_CODE);
    }

    /**
     * @return array{code:string[],cache:string[]}
     */
    private function getReloadPrefixes(): array
    {
        $server = Env::getInstance()->getConfig('wls');
        $configured = \is_array($server['reload_prefixes'] ?? null) ? $server['reload_prefixes'] : [];
        $default = self::DEFAULT_RELOAD_PREFIXES;

        return [
            'code' => \is_array($configured['code'] ?? null) ? $configured['code'] : $default['code'],
            'cache' => \is_array($configured['cache'] ?? null) ? $configured['cache'] : $default['cache'],
        ];
    }

    private function resolveReloadType(string $command): ?string
    {
        $prefixes = $this->getReloadPrefixes();
        foreach ($prefixes['code'] ?? [] as $prefix) {
            if (\str_starts_with($command, $prefix)) {
                return self::RELOAD_TYPE_CODE;
            }
        }

        foreach ($prefixes['cache'] ?? [] as $prefix) {
            if (\str_starts_with($command, $prefix)) {
                return self::RELOAD_TYPE_CACHE;
            }
        }

        return null;
    }

    private function getDispatchService(): BroadcastControlDispatchService
    {
        return ObjectManager::getInstance(BroadcastControlDispatchService::class);
    }
}

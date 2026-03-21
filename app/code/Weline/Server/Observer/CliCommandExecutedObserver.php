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

        $printer->note((string)__('WLS 通知：%{1}', [$result['message']]));
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

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

    /**
     * 显式命令（不走前缀匹配）：这些命令会切换运行世代，必须让 Worker 重载后读新世代。
     */
    private const EXACT_CODE_RELOAD_COMMANDS = ['core:update', 'theme:update'];

    /**
     * theme:update 只查看/只构建不切换的子动作，不触发重载。
     */
    private const THEME_UPDATE_NO_RELOAD_ACTIONS = ['status', 'list', 'gc', 'build', 'help'];

    public function execute(Event &$event): void
    {
        $command = (string)($event->getData('command') ?? '');
        $args = (array)($event->getData('args') ?? []);
        if ($command === '') {
            return;
        }
        // setup:upgrade 内部调起的优化子命令：父命令收尾统一 reload，子命令跳过。
        if (\getenv('WELINE_SETUP_CHILD') !== false) {
            return;
        }

        foreach (self::SKIP_RELOAD_COMMANDS as $skipPrefix) {
            if (\str_starts_with($command, $skipPrefix)) {
                return;
            }
        }

        $reloadType = $this->resolveReloadType($command, $args);
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
        $reloadTimeout = 1.5;
        try {
            $reloadTimeout = (float)Env::getInstance()->getConfig('setup.reload_timeout', 1.5);
        } catch (\Throwable) {
        }
        $reloadTimeout = max(0.2, min(30.0, $reloadTimeout));
        $result = $this->getDispatchService()->reloadAsync(null, ControlMessage::RELOAD_TYPE_CODE, $reloadTimeout);

        $message = (string)__('WLS 通知：%{1}', [$result['message']]);
        if (!empty($result['success'])) {
            $printer->note($message, '', Printing::SUCCESS);
        } else {
            $printer->note($message, '', Printing::DEEP_ORANGE);
        }

        // setup:upgrade 等在命令内先关维护，本观察者再异步 reload。
        // Direct 拓扑下 reload/补位可能留下粘性 Worker 门禁；cleanup 也可能未写掉 env。
        // setup: 收尾意图恒为「退出升级 503」：强制写 env=false 并再推一次 disable（禁止因粘 true 直接 return）。
        if ($reloadType === self::RELOAD_TYPE_CODE && \str_starts_with($command, 'setup:')) {
            if (!$this->shouldSkipMaintenanceReconcile($args)) {
                $this->reconcileMaintenanceOffAfterSetupReload($printer);
            }
        }
    }

    private function reconcileMaintenanceOffAfterSetupReload(Printing $printer): void
    {
        try {
            // 升级收尾后必须退出升级 503：即便 env 仍粘 true（cleanup 失败/竞态）也强制清掉。
            Env::getInstance()->setConfig('system.maintenance', false);
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

    private function shouldSkipMaintenanceReconcile(array $args): bool
    {
        if (array_key_exists('keep-maintenance', $args)) {
            return true;
        }
        foreach (array_keys($args) as $key) {
            if (is_string($key) && str_contains($key, 'keep-maintenance')) {
                return true;
            }
        }
        return false;
    }

    public static function triggerReload(string $type = self::RELOAD_TYPE_CODE): void
    {
        $dispatchService = ObjectManager::getInstance(BroadcastControlDispatchService::class);
        if ($type === self::RELOAD_TYPE_CACHE) {
            $dispatchService->cacheClear();
            return;
        }

        $reloadTimeout = 1.5;
        try {
            $reloadTimeout = (float)Env::getInstance()->getConfig('setup.reload_timeout', 1.5);
        } catch (\Throwable) {
        }
        $reloadTimeout = max(0.2, min(30.0, $reloadTimeout));
        $dispatchService->reloadAsync(null, ControlMessage::RELOAD_TYPE_CODE, $reloadTimeout);
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

    private function resolveReloadType(string $command, array $args = []): ?string
    {
        if (\in_array(\strtolower($command), self::EXACT_CODE_RELOAD_COMMANDS, true)) {
            return $this->exactCommandReloads($command, $args) ? self::RELOAD_TYPE_CODE : null;
        }

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

    /**
     * 显式命令是否真的切换了运行世代：
     * - core:update 一定会改写代码/产物，恒需重载；
     * - theme:update 只有切换指针（apply/auto/promote/rollback）才需重载，
     *   status/list/gc/build/dry-run 不动线上世代，不重载。
     */
    private function exactCommandReloads(string $command, array $args): bool
    {
        if (\strtolower($command) !== 'theme:update') {
            return true;
        }

        foreach (\array_keys($args) as $key) {
            if (\is_string($key) && \str_starts_with($key, 'dry-run')) {
                return false;
            }
        }

        $positional = [];
        foreach ($args as $key => $value) {
            if (\is_int($key) && \is_string($value)) {
                $positional[] = \strtolower(\trim($value));
            }
        }
        // 数字键首段是命令名（可能是别名 theme:up），先剥掉再看子动作。
        if ($positional !== [] && \str_contains($positional[0], ':')) {
            \array_shift($positional);
        }
        $action = $positional[0] ?? 'apply';

        return !\in_array($action, self::THEME_UPDATE_NO_RELOAD_ACTIONS, true);
    }

    private function getDispatchService(): BroadcastControlDispatchService
    {
        return ObjectManager::getInstance(BroadcastControlDispatchService::class);
    }
}

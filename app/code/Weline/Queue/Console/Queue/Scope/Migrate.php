<?php

declare(strict_types=1);

namespace Weline\Queue\Console\Queue\Scope;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Queue\Service\QueueScopeMigrationService;

/**
 * P1B-002：Queue Scope 映射工具（help/preflight/quarantine，禁止 apply）。
 *
 * 用法：
 *   php bin/w queue:scope:migrate help
 *   php bin/w queue:scope:migrate preflight
 *   php bin/w queue:scope:migrate quarantine
 *   php bin/w queue:scope:migrate verify
 */
class Migrate extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): string
    {
        $printing = ObjectManager::getInstance(Printing::class);
        /** @var QueueScopeMigrationService $service */
        $service = ObjectManager::getInstance(QueueScopeMigrationService::class);

        $action = '';
        foreach ($args as $arg) {
            $arg = \strtolower(\trim((string)$arg));
            if (\in_array($arg, ['help', 'preflight', 'quarantine', 'verify'], true)) {
                $action = $arg;
                break;
            }
        }
        if ($action === '' || $action === 'help') {
            // `php bin/w queue:scope:migrate` / `... help` / `-h` 均走 help 契约输出。
            if ($action === '' && $this->wantsCommandHelp($args)) {
                $printing->printing($this->tip(), 'success');
                return $this->tip();
            }
            $result = $service->help();
            $encoded = \json_encode($result, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) ?: '{}';
            $printing->printing('QUEUE_SCOPE help: ' . $encoded, 'success');

            return 'QUEUE_SCOPE help: ' . $encoded;
        }

        try {
            $result = match ($action) {
                'preflight' => $service->preflight(),
                'quarantine' => $service->quarantine(),
                'verify' => $service->verify(),
            };
        } catch (\LogicException $e) {
            $printing->printing($e->getMessage(), 'error');

            return 'QUEUE_SCOPE_ERROR: ' . $e->getMessage();
        }

        $encoded = \json_encode($result, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) ?: '{}';
        $ok = !\array_key_exists('ok', $result)
            || $result['ok'] === true;
        if (\array_key_exists('conservation_ok', $result)) {
            $ok = $ok && $result['conservation_ok'] === true;
        }
        $printing->printing("QUEUE_SCOPE {$action}: " . $encoded, $ok ? 'success' : 'error');

        return "QUEUE_SCOPE {$action}: " . $encoded;
    }

    public function tip(): string
    {
        return (string)__(
            'Queue Scope 映射工具：help/preflight/quarantine/verify（禁止 apply；信封 cutover 归 TASK-MIG-P1A）'
        );
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'queue:scope:migrate',
            $this->tip(),
            [
                'help' => '打印冻结 producer 映射契约',
                'preflight' => '只读清点遗留行并分类',
                'quarantine' => '对歧义 unfinished 行写入不可领取标记',
                'verify' => '复核 unfinished 遗留行是否均已 quarantine',
                '-h, --help' => '显示本帮助',
            ],
            [],
            [
                'php bin/w queue:scope:migrate help',
                'php bin/w queue:scope:migrate preflight',
                'php bin/w queue:scope:migrate quarantine',
                'php bin/w queue:scope:migrate verify',
            ]
        );
    }

    /**
     * @param array<int|string, mixed> $args
     */
    private function wantsCommandHelp(array $args): bool
    {
        foreach ($args as $arg) {
            $arg = \strtolower(\trim((string)$arg));
            if ($arg === '-h' || $arg === '--help') {
                return true;
            }
        }

        return false;
    }
}

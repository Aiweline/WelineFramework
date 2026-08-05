<?php

declare(strict_types=1);

namespace Weline\Eav\Console\Eav\Scope;

use Weline\Eav\Service\EavScopeMigrationService;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

/**
 * P1B-005：EAV Scope 工具（help/preflight/ensure-columns；禁止 apply）。
 */
class Migrate extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): string
    {
        $printing = ObjectManager::getInstance(Printing::class);
        /** @var EavScopeMigrationService $service */
        $service = ObjectManager::getInstance(EavScopeMigrationService::class);

        $action = '';
        foreach ($args as $arg) {
            $arg = \strtolower(\trim((string)$arg));
            if (\in_array($arg, ['help', 'preflight', 'ensure-columns', 'ensure_columns', 'apply'], true)) {
                $action = $arg === 'ensure_columns' ? 'ensure-columns' : $arg;
                break;
            }
        }
        if ($action === '' || $action === 'help') {
            $result = $service->help();
            $encoded = \json_encode($result, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) ?: '{}';
            $printing->printing('EAV_SCOPE help: ' . $encoded, 'success');

            return 'EAV_SCOPE help: ' . $encoded;
        }

        try {
            $result = match ($action) {
                'preflight' => $service->preflight(),
                'ensure-columns' => $service->ensureColumns(),
                'apply' => $service->apply(),
            };
        } catch (\Throwable $e) {
            $msg = $e->getMessage();
            $printing->printing('EAV_SCOPE apply refused: ' . $msg, 'error');

            return 'EAV_SCOPE_ERROR: ' . $msg;
        }

        $encoded = \json_encode($result, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) ?: '{}';
        $printing->printing("EAV_SCOPE {$action}: " . $encoded, 'success');

        return "EAV_SCOPE {$action}: " . $encoded;
    }

    public function tip(): string
    {
        return (string)__(
            'EAV Scope 工具：help/preflight/ensure-columns（apply 仅隔离 clone，经 scope:migrate-p1a）'
        );
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'eav:scope:migrate',
            $this->tip(),
            [
                'help' => '打印 typed Scope 列契约',
                'preflight' => '清点值表是否已有 Scope 列与遗留行',
                'ensure-columns' => '对缺失列执行 ADD COLUMN IF NOT EXISTS',
                'apply' => '共享库硬拒绝；行级 cutover 请用 scope:migrate-p1a --database=mig_clone_*',
                '-h, --help' => '显示本帮助',
            ],
            [],
            [
                'php bin/w eav:scope:migrate help',
                'php bin/w eav:scope:migrate preflight',
                'php bin/w eav:scope:migrate ensure-columns',
            ]
        );
    }

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

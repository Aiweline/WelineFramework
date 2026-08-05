<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Console\Scope;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\SystemConfig\Service\ScopeConfigMigrationService;

/**
 * MIG-P1B：短 Scope → 三段 typed Scope 迁移。
 *
 * apply/verify/rollback 写路径必须带 --database=mig_clone_*。
 */
class MigrateP1b extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): string
    {
        $printing = ObjectManager::getInstance(Printing::class);

        $action = '';
        $database = '';
        foreach ($args as $i => $arg) {
            $raw = \trim((string)$arg);
            $lower = \strtolower($raw);
            if (\in_array($lower, ['preflight', 'apply', 'verify', 'rollback'], true)) {
                $action = $lower;
                continue;
            }
            if (\str_starts_with($lower, '--database=')) {
                $database = \substr($raw, 11);
                continue;
            }
            if ($lower === '--database' && isset($args[$i + 1])) {
                $database = (string)$args[$i + 1];
                continue;
            }
            if (\str_starts_with($lower, '--db=')) {
                $database = \substr($raw, 5);
            }
        }
        if ($action === '') {
            $message = (string)__('用法：scope:migrate-p1b preflight|apply|verify|rollback [--database=mig_clone_*]');
            $printing->printing($message, 'warning');

            return $message;
        }

        $target = null;
        if ($database !== '') {
            $envPath = (\defined('BP') ? BP : \dirname(__DIR__, 6)) . '/app/etc/env.php';
            $env = \is_file($envPath) ? include $envPath : [];
            if (!\is_array($env)) {
                $env = [];
            }
            $db = $env['db']['master'] ?? $env['db'] ?? [];
            if (!\is_array($db)) {
                $db = [];
            }
            $target = [
                'type' => (string)($db['type'] ?? 'pgsql'),
                'hostname' => (string)($db['hostname'] ?? '127.0.0.1'),
                'hostport' => (string)($db['hostport'] ?? '5432'),
                'database' => $database,
                'username' => (string)($db['username'] ?? ''),
                'password' => (string)($db['password'] ?? ''),
            ];
        }

        try {
            /** @var ScopeConfigMigrationService $migration */
            $migration = ObjectManager::getInstance(ScopeConfigMigrationService::class);
            $result = match ($action) {
                'preflight' => $migration->preflight(),
                'apply' => $migration->apply($target),
                'verify' => $migration->verify($target),
                'rollback' => $migration->rollback($target),
            };
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'error' => $e->getMessage()];
        }

        $encoded = \json_encode($result, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) ?: '{}';
        $ok = !\array_key_exists('ok', $result) || $result['ok'] === true;
        if (isset($result['error'])) {
            $ok = false;
        }
        $printing->printing("MIG-P1B {$action}: " . $encoded, $ok ? 'success' : 'error');

        return "MIG-P1B {$action}: " . $encoded;
    }

    public function tip(): string
    {
        return (string)__('MIG-P1B 配置 Scope 迁移：preflight/apply/verify/rollback（apply 必须 --database=mig_clone_*）');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'scope:migrate-p1b',
            $this->tip(),
            [
                'preflight' => '只读清点短 Scope / 冲突（可在共享库）',
                'apply' => '隔离 clone 上确定映射；裸 default 冲突隔离',
                'verify' => '复核未完成 mappable=0',
                'rollback' => '报告不恢复短 Scope write（TEST-MIG-P1B-07）',
                '--database=mig_clone_*' => '隔离目标库（apply 必填）',
            ],
            [],
            [
                'php bin/w scope:migrate-p1b preflight',
                'php bin/w mig:foundation clone-create --mode=schema --purpose=p1b',
                'php bin/w scope:migrate-p1b apply --database=mig_clone_p1b_...',
                'php bin/w scope:migrate-p1b verify --database=mig_clone_p1b_...',
                'php bin/w scope:migrate-p1b rollback --database=mig_clone_p1b_...',
                'php bin/w mig:foundation clone-destroy --database=mig_clone_p1b_...',
            ]
        );
    }
}

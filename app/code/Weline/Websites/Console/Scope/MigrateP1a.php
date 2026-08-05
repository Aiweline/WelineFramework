<?php

declare(strict_types=1);

namespace Weline\Websites\Console\Scope;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Websites\Service\ScopeMigrationService;

/**
 * MIG-P1A：Scope 基础迁移命令。
 *
 * apply/verify/rollback 写路径必须带 --database=mig_clone_*。
 */
class MigrateP1a extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): int
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
            $message = (string)__('用法：scope:migrate-p1a preflight|apply|verify|rollback [--database=mig_clone_*]');
            $printing->printing($message, 'warning');

            return 2;
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
            /** @var ScopeMigrationService $migration */
            $migration = ObjectManager::getInstance(ScopeMigrationService::class);
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
        $printing->printing("MIG-P1A {$action}: " . $encoded, $ok ? 'success' : 'error');

        return $ok ? 0 : 2;
    }

    public function tip(): string
    {
        return (string)__('MIG-P1A Scope 迁移：preflight/apply/verify/rollback（apply 必须 --database=mig_clone_*）');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'scope:migrate-p1a',
            $this->tip(),
            [
                'preflight' => '只读清点（可在共享库）',
                'apply' => '隔离 clone 上补种 Store/Channel + Queue/EAV cutover',
                'verify' => '从数据库复核不变量（建议同 --database）',
                'rollback' => '报告 additive 保留与不放宽 write（TEST-MIG-P1A-08）',
                '--database=mig_clone_*' => '隔离目标库（apply 必填）',
            ],
            [],
            [
                'php bin/w scope:migrate-p1a preflight',
                'php bin/w mig:foundation clone-create --mode=schema --purpose=p1a',
                'php bin/w scope:migrate-p1a apply --database=mig_clone_p1a_...',
                'php bin/w scope:migrate-p1a verify --database=mig_clone_p1a_...',
                'php bin/w scope:migrate-p1a rollback --database=mig_clone_p1a_...',
                'php bin/w mig:foundation clone-destroy --database=mig_clone_p1a_...',
            ]
        );
    }
}

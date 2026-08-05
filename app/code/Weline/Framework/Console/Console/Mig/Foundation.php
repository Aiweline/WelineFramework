<?php

declare(strict_types=1);

namespace Weline\Framework\Console\Console\Mig;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Database\Migration\Service\DatabaseFingerprintGuard;
use Weline\Framework\Database\Migration\Service\MigrationCheckpointJournalStore;
use Weline\Framework\Database\Migration\Service\MigrationCheckpointService;
use Weline\Framework\Database\Migration\Service\MigrationCloneService;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

/**
 * TASK-MIG-FOUNDATION：迁移底座 CLI（help/preflight/clone-create|destroy|list/journal-list|verify；共享库硬拒绝）。
 */
class Foundation extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): int
    {
        $printing = ObjectManager::getInstance(Printing::class);

        $action = 'help';
        $dbOverride = '';
        $mode = MigrationCloneService::MODE_SCHEMA;
        $purpose = 'foundation';
        $destroyDb = '';
        $checkpointId = '';
        foreach ($args as $i => $arg) {
            $raw = \trim((string)$arg);
            $lower = \strtolower($raw);
            if (\in_array($lower, [
                'help', 'preflight', 'clone-create', 'clone-destroy', 'clone-list',
                'journal-verify', 'journal-list',
            ], true)) {
                $action = $lower;
                continue;
            }
            if (\str_starts_with($lower, '--db=')) {
                $dbOverride = \substr($raw, 5);
                continue;
            }
            if ($lower === '--db' && isset($args[$i + 1])) {
                $dbOverride = (string)$args[$i + 1];
                continue;
            }
            if (\str_starts_with($lower, '--mode=')) {
                $mode = \strtolower(\substr($raw, 7));
                continue;
            }
            if ($lower === '--mode' && isset($args[$i + 1])) {
                $mode = \strtolower((string)$args[$i + 1]);
                continue;
            }
            if (\str_starts_with($lower, '--purpose=')) {
                $purpose = \substr($raw, 10);
                continue;
            }
            if ($lower === '--purpose' && isset($args[$i + 1])) {
                $purpose = (string)$args[$i + 1];
                continue;
            }
            if (\str_starts_with($lower, '--database=')) {
                $destroyDb = \substr($raw, 11);
                continue;
            }
            if ($lower === '--database' && isset($args[$i + 1])) {
                $destroyDb = (string)$args[$i + 1];
                continue;
            }
            if (\str_starts_with($lower, '--checkpoint=')) {
                $checkpointId = \substr($raw, 13);
                continue;
            }
            if ($lower === '--checkpoint' && isset($args[$i + 1])) {
                $checkpointId = (string)$args[$i + 1];
            }
        }

        if ($action === 'help') {
            $result = [
                'mode' => 'help',
                'notes' => [
                    'TASK-MIG-FOUNDATION：immutable manifest、指纹 denylist、持久化 journal、rollout gate、隔离 clone。',
                    '共享库名 weline/prod/canonical 在写入前硬拒绝。',
                    '隔离库名必须 mig_clone_*|weline_mig_*|test_mig_*。',
                    'clone-create 默认 schema-only；源库仅作只读 dump，从不作为 apply 目标。',
                    'journal 默认目录 var/mig/checkpoints；fresh-connection：journal-verify --checkpoint=ID',
                    'CLI：php bin/w mig:foundation help|preflight|clone-*|journal-list|journal-verify',
                ],
            ];

            return $this->emit($printing, 'MIG_FOUNDATION help', $result, true);
        }

        if ($action === 'journal-list') {
            $store = new MigrationCheckpointJournalStore();
            $ids = $store->listIds();

            return $this->emit($printing, 'MIG_FOUNDATION journal-list', [
                'directory' => $store->directory(),
                'count' => \count($ids),
                'checkpoint_ids' => $ids,
            ], true);
        }

        if ($action === 'journal-verify') {
            if ($checkpointId === '') {
                return $this->emit($printing, 'MIG_FOUNDATION journal-verify', [
                    'ok' => false,
                    'error' => 'missing --checkpoint=ID',
                ], false);
            }
            $service = MigrationCheckpointService::withDefaultStore();
            $result = $service->verifyFresh($checkpointId);

            return $this->emit($printing, 'MIG_FOUNDATION journal-verify', $result, !empty($result['ok']));
        }

        $source = $this->masterDbConfig();

        if ($action === 'preflight') {
            /** @var MigrationCloneService $cloneService */
            $cloneService = ObjectManager::getInstance(MigrationCloneService::class);
            $target = $source;
            if ($dbOverride !== '') {
                $target['database'] = $dbOverride;
            }
            // 有 --db 时用登记簿 allowlist；无覆盖时用空 allowlist（仅 denylist/命名，共享库必拒）
            $guard = $dbOverride !== ''
                ? $cloneService->guardedFingerprint()
                : new DatabaseFingerprintGuard();
            $service = new MigrationCheckpointService($guard);
            $result = $service->preflight([
                'type' => (string)($target['type'] ?? 'pgsql'),
                'hostname' => (string)($target['hostname'] ?? '127.0.0.1'),
                'hostport' => (string)($target['hostport'] ?? '5432'),
                'database' => (string)($target['database'] ?? ''),
                'username' => (string)($target['username'] ?? ''),
            ]);
            $ok = !empty($result['ok']);

            return $this->emit($printing, 'MIG_FOUNDATION preflight', $result, $ok);
        }

        /** @var MigrationCloneService $cloneService */
        $cloneService = ObjectManager::getInstance(MigrationCloneService::class);

        if ($action === 'clone-list') {
            $items = [];
            foreach ($cloneService->list() as $handle) {
                $items[] = $handle->toArray();
            }

            return $this->emit($printing, 'MIG_FOUNDATION clone-list', ['count' => \count($items), 'items' => $items], true);
        }

        if ($action === 'clone-create') {
            try {
                $handle = $cloneService->create($source, $mode, $purpose, 'cli');
                $preflight = (new MigrationCheckpointService($cloneService->guardedFingerprint()))->preflight($handle->config);

                return $this->emit($printing, 'MIG_FOUNDATION clone-create', [
                    'handle' => $handle->toArray(),
                    'preflight' => $preflight,
                ], !empty($preflight['ok']));
            } catch (\Throwable $e) {
                return $this->emit($printing, 'MIG_FOUNDATION clone-create', [
                    'ok' => false,
                    'error' => $e->getMessage(),
                ], false);
            }
        }

        if ($action === 'clone-destroy') {
            if ($destroyDb === '' && $dbOverride !== '') {
                $destroyDb = $dbOverride;
            }
            if ($destroyDb === '') {
                return $this->emit($printing, 'MIG_FOUNDATION clone-destroy', [
                    'ok' => false,
                    'error' => 'missing --database=mig_clone_...',
                ], false);
            }
            try {
                $cloneService->destroy($destroyDb, $source);

                return $this->emit($printing, 'MIG_FOUNDATION clone-destroy', [
                    'ok' => true,
                    'database' => $destroyDb,
                ], true);
            } catch (\Throwable $e) {
                return $this->emit($printing, 'MIG_FOUNDATION clone-destroy', [
                    'ok' => false,
                    'error' => $e->getMessage(),
                ], false);
            }
        }

        return $this->emit($printing, 'MIG_FOUNDATION', ['ok' => false, 'error' => 'unknown_action:' . $action], false);
    }

    public function tip(): string
    {
        return (string)__('迁移底座：help/preflight/clone 与 journal 子命令（共享库零写硬拒绝；journal 持久化）');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'mig:foundation',
            $this->tip(),
            [
                'help' => '打印 foundation 契约',
                'preflight' => '隔离指纹检查；可 --db=mig_clone_xxx 对登记 clone 校验',
                'clone-create' => '创建隔离 schema clone（默认 --mode=schema）并登记 allowlist',
                'clone-destroy' => '销毁隔离 clone（--database=mig_clone_...）',
                'clone-list' => '列出登记簿中的 clone',
                'journal-list' => '列出持久化 checkpoint journal',
                'journal-verify' => 'fresh-connection 校验 journal（--checkpoint=ID）',
                '--db=NAME' => 'preflight 目标库名覆盖',
                '--mode=schema|full' => 'clone-create 模式',
                '--purpose=NAME' => 'clone 用途后缀',
                '--database=NAME' => 'clone-destroy 目标库',
                '--checkpoint=ID' => 'journal-verify 目标',
                '-h, --help' => '显示本帮助',
            ],
            [],
            [
                'php bin/w mig:foundation help',
                'php bin/w mig:foundation preflight',
                'php bin/w mig:foundation clone-create --mode=schema --purpose=foundation',
                'php bin/w mig:foundation preflight --db=mig_clone_foundation_...',
                'php bin/w mig:foundation clone-destroy --database=mig_clone_foundation_...',
                'php bin/w mig:foundation clone-list',
                'php bin/w mig:foundation journal-list',
                'php bin/w mig:foundation journal-verify --checkpoint=cp-1',
            ]
        );
    }

    /**
     * @return array{type:string,hostname:string,hostport:string,database:string,username:string,password:string}
     */
    private function masterDbConfig(): array
    {
        $envPath = (\defined('BP') ? BP : \dirname(__DIR__, 7)) . '/app/etc/env.php';
        $env = \is_file($envPath) ? include $envPath : [];
        if (!\is_array($env)) {
            $env = [];
        }
        $db = $env['db']['master'] ?? $env['db'] ?? [];
        if (!\is_array($db)) {
            $db = [];
        }

        return [
            'type' => (string)($db['type'] ?? 'pgsql'),
            'hostname' => (string)($db['hostname'] ?? '127.0.0.1'),
            'hostport' => (string)($db['hostport'] ?? '5432'),
            'database' => (string)($db['database'] ?? ''),
            'username' => (string)($db['username'] ?? ''),
            'password' => (string)($db['password'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $result
     */
    private function emit(Printing $printing, string $label, array $result, bool $ok): int
    {
        $encoded = \json_encode($result, \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) ?: '{}';
        $line = $label . ': ' . $encoded;
        $printing->printing($line, $ok ? 'success' : 'error');

        return $ok ? 0 : 2;
    }
}

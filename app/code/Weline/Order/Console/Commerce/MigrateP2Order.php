<?php

declare(strict_types=1);

namespace Weline\Order\Console\Commerce;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Order\Service\OrderCutoverGate;
use Weline\Order\Service\OrderCutoverMigrationService;

/**
 * MIG-P2-ORDER：Checkout→Order 单写切流。
 *
 * preflight/apply/verify/rollback 必须 --database=mig_clone_*（禁止共享 weline）。
 * 命令名：commerce:migrate-p2-order。
 */
class MigrateP2Order extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): int
    {
        $printing = ObjectManager::getInstance(Printing::class);

        $action = '';
        $database = '';
        $checkpointId = '';
        $productionOnToken = '';
        foreach ($args as $i => $arg) {
            $raw = trim((string) $arg);
            $lower = strtolower($raw);
            if (in_array($lower, ['help', 'preflight', 'apply', 'verify', 'rollback'], true)) {
                $action = $lower;
                continue;
            }
            if (str_starts_with($lower, '--database=')) {
                $database = substr($raw, 11);
                continue;
            }
            if ($lower === '--database' && isset($args[$i + 1])) {
                $database = (string) $args[$i + 1];
                continue;
            }
            if (str_starts_with($lower, '--db=')) {
                $database = substr($raw, 5);
                continue;
            }
            if (str_starts_with($lower, '--checkpoint=')) {
                $checkpointId = substr($raw, 13);
                continue;
            }
            if ($lower === '--checkpoint' && isset($args[$i + 1])) {
                $checkpointId = (string) $args[$i + 1];
                continue;
            }
            if (str_starts_with($lower, '--production-on-token=')) {
                $productionOnToken = substr($raw, 22);
                continue;
            }
            if ($lower === '--production-on-token' && isset($args[$i + 1])) {
                $productionOnToken = (string) $args[$i + 1];
            }
        }

        if ($action === '' || $action === 'help') {
            $help = $this->help();
            $encoded = is_array($help)
                ? (json_encode($help, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}')
                : (string) $help;
            $printing->printing($encoded, 'success');

            return 0;
        }

        $target = null;
        if ($database !== '') {
            $envPath = (defined('BP') ? BP : dirname(__DIR__, 6)) . '/app/etc/env.php';
            $env = is_file($envPath) ? include $envPath : [];
            if (!is_array($env)) {
                $env = [];
            }
            $db = $env['db']['master'] ?? $env['db'] ?? [];
            if (!is_array($db)) {
                $db = [];
            }
            $target = [
                'type' => (string) ($db['type'] ?? 'pgsql'),
                'hostname' => (string) ($db['hostname'] ?? '127.0.0.1'),
                'hostport' => (string) ($db['hostport'] ?? '5432'),
                'database' => $database,
                'username' => (string) ($db['username'] ?? ''),
                'password' => (string) ($db['password'] ?? ''),
                'prefix' => (string) ($db['prefix'] ?? ''),
            ];
        }

        try {
            /** @var OrderCutoverMigrationService $migration */
            $migration = ObjectManager::getInstance(OrderCutoverMigrationService::class);
            $result = match ($action) {
                'preflight' => $migration->preflight($target),
                'apply' => $migration->apply($target, $productionOnToken),
                'verify' => $migration->verify($target, $checkpointId),
                'rollback' => $migration->rollbackUi(
                    OrderCutoverGate::MODE_SHADOW,
                    $target,
                    $checkpointId,
                ),
            };
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'error' => $e->getMessage()];
        }

        $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}';
        $ok = !array_key_exists('ok', $result) || $result['ok'] === true;
        if (isset($result['error'])) {
            $ok = false;
        }
        $printing->printing("MIG-P2-ORDER {$action}: " . $encoded, $ok ? 'success' : 'error');

        return $ok ? 0 : 2;
    }

    public function tip(): string
    {
        return (string) \__('MIG-P2-ORDER：Checkout→Order 单写切流（仅登记的隔离 clone）');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'commerce:migrate-p2-order',
            $this->tip(),
            [
                'help' => '打印帮助',
                'preflight' => '只读清点 Product shard / writers / 水位（需 --database）',
                'apply' => 'checkpoint 后切换单写（需显式 token）',
                'verify' => '新进程按 checkpoint 复核指纹、journal、水位和 writer',
                'rollback' => '按 checkpoint 受控回 shadow；绝不恢复旧 writer',
                '--database=' => 'migration registry 已登记的隔离库名 mig_clone_*',
                '--checkpoint=' => 'apply 输出的 checkpoint ID（verify/rollback 必需）',
                '--production-on-token=' => 'apply 显式授权；仅校验非空且不会写入输出/journal',
                '-h, --help' => '显示本帮助',
            ],
            [],
            [
                'php bin/w commerce:migrate-p2-order help',
                'php bin/w mig:foundation clone-create --mode=full --purpose=p2order',
                'php bin/w commerce:migrate-p2-order preflight --database=mig_clone_p2order_...',
                'php bin/w commerce:migrate-p2-order apply --database=mig_clone_p2order_... --production-on-token=<one-time>',
                'php bin/w commerce:migrate-p2-order verify --database=mig_clone_p2order_... --checkpoint=p2ord-...',
                'php bin/w commerce:migrate-p2-order rollback --database=mig_clone_p2order_... --checkpoint=p2ord-...',
            ]
        );
    }
}

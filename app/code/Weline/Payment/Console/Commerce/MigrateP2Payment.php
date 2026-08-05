<?php

declare(strict_types=1);

namespace Weline\Payment\Console\Commerce;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Payment\Service\PaymentCompatibilityMigrationService;

/**
 * MIG-P2-PAYMENT：历史 Transaction → Intent/Attempt 兼容映射。
 *
 * 所有数据库动作必须 --database=mig_clone_*；verify/rollback 还必须携带
 * apply 输出的 checkpoint，确保新 CLI 进程可独立复核。
 */
class MigrateP2Payment extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): int
    {
        $printing = ObjectManager::getInstance(Printing::class);

        $action = '';
        $database = '';
        $checkpointId = '';
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
            /** @var PaymentCompatibilityMigrationService $migration */
            $migration = ObjectManager::getInstance(PaymentCompatibilityMigrationService::class);
            $result = match ($action) {
                'preflight' => $migration->preflight($target),
                'apply' => $migration->apply($target),
                'verify' => $migration->verify($target, $checkpointId),
                'rollback' => $migration->rollbackToModeOff($target, $checkpointId),
            };
        } catch (\Throwable $e) {
            $result = ['ok' => false, 'error' => $e->getMessage()];
        }

        $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}';
        $ok = !array_key_exists('ok', $result) || $result['ok'] === true;
        if (isset($result['error'])) {
            $ok = false;
        }
        $printing->printing("MIG-P2-PAYMENT {$action}: " . $encoded, $ok ? 'success' : 'error');

        return $ok ? 0 : 2;
    }

    public function tip(): string
    {
        return (string) __('MIG-P2-PAYMENT：历史 Transaction→Intent/Attempt（仅登记的隔离 clone）');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'commerce:migrate-p2-payment',
            $this->tip(),
            [
                'help' => \__('打印帮助'),
                'preflight' => \__('只读清点历史、冲突、Schema 与全部财务水位'),
                'apply' => \__('checkpoint 后在登记 clone 上事务映射'),
                'verify' => \__('新进程复核守恒、唯一性、水位和 journal'),
                'rollback' => \__('按 checkpoint 关闭 rollout；保留全部事实'),
                '--database=' => \__('migration registry 已登记的 mig_clone_*'),
                '--checkpoint=' => \__('apply 输出的 checkpoint ID（verify/rollback 必需）'),
                '-h, --help' => \__('显示本帮助'),
            ],
            [],
            [
                'php bin/w commerce:migrate-p2-payment help',
                'php bin/w commerce:migrate-p2-payment preflight --database=mig_clone_p2payment_...',
                'php bin/w mig:foundation clone-create --mode=schema --purpose=p2-payment',
                'php bin/w commerce:migrate-p2-payment apply --database=mig_clone_p2-payment_...',
                'php bin/w commerce:migrate-p2-payment verify --database=mig_clone_p2-payment_... --checkpoint=p2pay-...',
                'php bin/w commerce:migrate-p2-payment rollback --database=mig_clone_p2-payment_... --checkpoint=p2pay-...',
            ]
        );
    }
}

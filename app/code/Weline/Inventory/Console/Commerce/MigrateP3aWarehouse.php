<?php

declare(strict_types=1);

namespace Weline\Inventory\Console\Commerce;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Inventory\Service\WarehouseMigrationService;

/**
 * MIG-P3A：P2 ledger/reservation → 默认逻辑仓。
 *
 * 所有数据库动作必须使用 migration registry 已登记的 --database=mig_clone_*。
 * verify/allowlist/rollback 还必须携带 apply 输出的 checkpoint。
 * 命令名：commerce:migrate-p3a-warehouse。
 */
class MigrateP3aWarehouse extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): int
    {
        $printing = ObjectManager::getInstance(Printing::class);

        $action = '';
        $database = '';
        $checkpointId = '';
        $websiteIds = [];
        foreach ($args as $i => $arg) {
            $raw = trim((string) $arg);
            $lower = strtolower($raw);
            if (in_array(
                $lower,
                ['help', 'preflight', 'apply', 'verify', 'allowlist', 'rollback'],
                true,
            )) {
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
            if (str_starts_with($lower, '--website=')) {
                $websiteIds = array_merge(
                    $websiteIds,
                    $this->parseWebsiteIds(substr($raw, 10)),
                );
                continue;
            }
            if ($lower === '--website' && isset($args[$i + 1])) {
                $websiteIds = array_merge(
                    $websiteIds,
                    $this->parseWebsiteIds((string) $args[$i + 1]),
                );
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
            /** @var WarehouseMigrationService $migration */
            $migration = ObjectManager::getInstance(WarehouseMigrationService::class);
            $result = match ($action) {
                'preflight' => $migration->preflight($target),
                'apply' => $migration->apply($target),
                'verify' => $migration->verify($target, $checkpointId),
                'allowlist' => $migration->allowlist($target, $checkpointId, $websiteIds),
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
        $printing->printing("MIG-P3A {$action}: " . $encoded, $ok ? 'success' : 'error');

        return $ok ? 0 : 2;
    }

    public function tip(): string
    {
        return (string) __('MIG-P3A：P2 库存迁到默认逻辑仓（仅登记的隔离 clone）');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'commerce:migrate-p3a-warehouse',
            $this->tip(),
            [
                'help' => __('打印帮助'),
                'preflight' => __('只读清点历史、映射冲突、Schema 与逐 Offer 守恒'),
                'apply' => __('checkpoint 后在登记 clone 上锁表事务映射；写入模式保持 off'),
                'verify' => __('新进程复核 checkpoint、历史摘要、映射计划与逐 Offer 守恒'),
                'allowlist' => __('verify 成功后按 website 开启新写路径'),
                'rollback' => __('按 checkpoint 关闭新写路径；保留历史与已映射事实'),
                '--database=' => __('migration registry 已登记的 mig_clone_*'),
                '--checkpoint=' => __('apply 输出的 checkpoint ID（verify/allowlist/rollback 必需）'),
                '--website=' => __('allowlist 网站 ID；可重复或逗号分隔'),
                '-h, --help' => __('显示本帮助'),
            ],
            [],
            [
                'php bin/w commerce:migrate-p3a-warehouse help',
                'php bin/w mig:foundation clone-create --mode=schema --purpose=p3awarehouse',
                'php bin/w commerce:migrate-p3a-warehouse preflight --database=mig_clone_p3awarehouse_...',
                'php bin/w commerce:migrate-p3a-warehouse apply --database=mig_clone_p3awarehouse_...',
                'php bin/w commerce:migrate-p3a-warehouse verify --database=mig_clone_p3awarehouse_... --checkpoint=p3awh-...',
                'php bin/w commerce:migrate-p3a-warehouse allowlist --database=mig_clone_p3awarehouse_... --checkpoint=p3awh-... --website=0',
                'php bin/w commerce:migrate-p3a-warehouse rollback --database=mig_clone_p3awarehouse_... --checkpoint=p3awh-...',
            ]
        );
    }

    /** @return list<int> */
    private function parseWebsiteIds(string $raw): array
    {
        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part === '' || !ctype_digit($part)) {
                continue;
            }
            $ids[] = (int) $part;
        }

        return $ids;
    }
}

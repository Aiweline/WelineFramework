<?php

declare(strict_types=1);

namespace Weline\Product\Console\Commerce;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Product\Service\ProductIdentityCutoverService;
use Weline\Product\Service\ProductV2ConflictException;
use Weline\Product\Service\ProductV2MigrationService;

/**
 * Non-production, clone-only Product V2 inventory/migration/cutover command.
 */
final class MigrateProductV2 extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): int
    {
        $parsed = $this->parse($args);
        $printing = ObjectManager::getInstance(Printing::class);
        if ($parsed['action'] === '' || $parsed['action'] === 'help') {
            $printing->printing(
                json_encode($this->help(), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}',
                'success',
            );
            return 0;
        }

        try {
            /** @var ProductV2MigrationService $migration */
            $migration = ObjectManager::getInstance(ProductV2MigrationService::class);
            $result = match ($parsed['action']) {
                'inventory' => $migration->inventory(),
                'dry-run' => $migration->migrate(true),
                'apply' => $this->onRegisteredClone(
                    $parsed['database'],
                    static fn (): array => $migration->migrate(false),
                ),
                'verify' => $this->onRegisteredClone(
                    $parsed['database'],
                    static fn (): array => $migration->verify(),
                ),
                'cutover' => $this->onRegisteredClone(
                    $parsed['database'],
                    static fn (): array => $migration->cutover($parsed['expected_version']),
                ),
                'rollback' => $this->onRegisteredClone(
                    $parsed['database'],
                    static fn (): array => $migration->rollback(
                        $parsed['expected_version'],
                        $parsed['mode'],
                    ),
                ),
                default => throw new \InvalidArgumentException('product_v2_migration_action_invalid'),
            };
        } catch (\Throwable $exception) {
            $result = [
                'ok' => false,
                'phase' => ProductV2MigrationService::PHASE,
                'error_code' => $exception instanceof ProductV2ConflictException
                    ? $exception->errorCode
                    : $exception->getMessage(),
                'error' => $exception->getMessage(),
            ];
        }
        $ok = ($result['ok'] ?? false) === true;
        $printing->printing(
            json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}',
            $ok ? 'success' : 'error',
        );
        return $ok ? 0 : 2;
    }

    public function tip(): string
    {
        return (string)__('万能产品 V2 身份盘点、迁移、验证与可回滚切换（仅 full clone）');
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'commerce:migrate-product-v2',
            $this->tip(),
            [
                'inventory' => __('只读统计旧 1:1 与 V2 身份、缺 Offer、冲突及切换状态'),
                'dry-run' => __('只读生成迁移决策，不写任何记录'),
                'apply' => __('在 mig_clone_* 上幂等迁移并进入 dual_read'),
                'verify' => __('逐条核验 Product UUID、Offer UUID、归属关系、SKU 和开放冲突'),
                'cutover' => __('验证摘要未过期时切换为 V2 权威并停止旧表写入'),
                'rollback' => __('仅回退读写模式，不删除任何 V2 身份'),
                '--database=' => __('必须与当前 env 配置一致的 mig_clone_* 数据库'),
                '--expected-version=' => __('cutover/rollback 必填的切换状态版本'),
                '--mode=' => __('rollback 目标：dual_read（默认）或 legacy'),
            ],
            [],
            [
                'php bin/w commerce:migrate-product-v2 inventory',
                'php bin/w commerce:migrate-product-v2 dry-run',
                'php bin/w commerce:migrate-product-v2 apply --database=mig_clone_product_v2_...',
                'php bin/w commerce:migrate-product-v2 verify --database=mig_clone_product_v2_...',
                'php bin/w commerce:migrate-product-v2 cutover --database=mig_clone_product_v2_... --expected-version=2',
                'php bin/w commerce:migrate-product-v2 rollback --database=mig_clone_product_v2_... --expected-version=3 --mode=dual_read',
            ],
        );
    }

    /**
     * @return array{action:string,database:string,expected_version:int,mode:string}
     */
    private function parse(array $args): array
    {
        $parsed = [
            'action' => '',
            'database' => '',
            'expected_version' => -1,
            'mode' => ProductIdentityCutoverService::MODE_DUAL_READ,
        ];
        foreach ($args as $index => $arg) {
            $raw = trim((string)$arg);
            $lower = strtolower($raw);
            if (in_array(
                $lower,
                ['help', 'inventory', 'dry-run', 'apply', 'verify', 'cutover', 'rollback'],
                true,
            )) {
                $parsed['action'] = $lower;
                continue;
            }
            if (str_starts_with($lower, '--database=')) {
                $parsed['database'] = trim(substr($raw, 11));
            } elseif ($lower === '--database' && isset($args[$index + 1])) {
                $parsed['database'] = trim((string)$args[$index + 1]);
            } elseif (str_starts_with($lower, '--expected-version=')) {
                $parsed['expected_version'] = (int)trim(substr($raw, 19));
            } elseif ($lower === '--expected-version' && isset($args[$index + 1])) {
                $parsed['expected_version'] = (int)$args[$index + 1];
            } elseif (str_starts_with($lower, '--mode=')) {
                $parsed['mode'] = strtolower(trim(substr($raw, 7)));
            } elseif ($lower === '--mode' && isset($args[$index + 1])) {
                $parsed['mode'] = strtolower(trim((string)$args[$index + 1]));
            }
        }
        return $parsed;
    }

    /** @return array<string,mixed> */
    private function onRegisteredClone(string $database, callable $action): array
    {
        $database = strtolower(trim($database));
        $configured = strtolower($this->configuredDatabase());
        if (!str_starts_with($database, 'mig_clone_') || $configured !== $database) {
            throw new \InvalidArgumentException(
                'product_v2_migration_requires_current_registered_mig_clone',
            );
        }
        return $action();
    }

    private function configuredDatabase(): string
    {
        $envPath = (defined('BP') ? BP : dirname(__DIR__, 6)) . '/app/etc/env.php';
        $env = is_file($envPath) ? include $envPath : [];
        $db = is_array($env) ? ($env['db']['master'] ?? $env['db'] ?? []) : [];
        return is_array($db) ? (string)($db['database'] ?? '') : '';
    }
}

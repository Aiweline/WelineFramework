<?php

declare(strict_types=1);

namespace Weline\CustomerAsset\Console\Commerce;

use Weline\CustomerAsset\Service\CustomerAssetMigrationService;
use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;

/**
 * MIG-P4D: registered PostgreSQL full clone -> checkpoint -> shadow -> allowlist.
 */
final class MigrateP4dCustomerAsset extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): int
    {
        $printing = ObjectManager::getInstance(Printing::class);
        $parsed = $this->parse($args);
        $action = $parsed['action'];

        if ($action === '' && $parsed['unknown_action'] === '') {
            $action = 'help';
        }
        if ($action === 'help') {
            $help = $this->help();
            $encoded = is_array($help)
                ? (json_encode($help, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}')
                : (string) $help;
            $printing->printing($encoded, 'success');

            return 0;
        }

        try {
            if ($parsed['unknown_action'] !== '') {
                throw new \InvalidArgumentException(
                    'mig_p4d_customer_asset_unknown_action:'
                    . $parsed['unknown_action'],
                );
            }
            $target = $this->target((string) $parsed['database']);
            /** @var CustomerAssetMigrationService $migration */
            $migration = ObjectManager::getInstance(
                CustomerAssetMigrationService::class,
            );
            $result = match ($action) {
                'preflight' => $migration->preflight(
                    $target,
                    $this->requireWebsite($parsed['website']),
                ),
                'apply' => $migration->apply(
                    $target,
                    $this->requireWebsite($parsed['website']),
                ),
                'verify' => $migration->verify(
                    $target,
                    (string) $parsed['checkpoint'],
                ),
                'allowlist' => $migration->allowlist(
                    $target,
                    (string) $parsed['checkpoint'],
                    $this->requireWebsite($parsed['website']),
                ),
                'rollback' => $migration->rollbackToModeOff(
                    $target,
                    (string) $parsed['checkpoint'],
                ),
            };
        } catch (\Throwable $exception) {
            $result = [
                'ok' => false,
                'phase' => CustomerAssetMigrationService::PHASE,
                'error' => $exception->getMessage(),
            ];
        }

        $encoded = json_encode(
            $result,
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
        ) ?: '{}';
        $ok = !array_key_exists('ok', $result) || $result['ok'] === true;
        if (isset($result['error'])) {
            $ok = false;
        }
        $printing->printing(
            "MIG-P4D {$action}: " . $encoded,
            $ok ? 'success' : 'error',
        );

        return $ok ? 0 : 2;
    }

    public function tip(): string
    {
        return (string) __(
            'MIG-P4D CustomerAsset balance, reservation and ledger conservation on a registered PostgreSQL full clone',
        );
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'commerce:migrate-p4d-customer-asset',
            $this->tip(),
            [
                'help' => __('打印帮助'),
                'preflight' => __('在 PostgreSQL full clone 只读重放 account/reservation/ledger'),
                'apply' => __('写前 checkpoint，验证守恒并保持 shadow'),
                'verify' => __('新进程重载 checkpoint，重算事实 hash 与守恒'),
                'allowlist' => __('fresh verify 后仅放量 checkpoint 冻结的 Website tender'),
                'rollback' => __('按 checkpoint mode off，保留 ledger 与既有结算义务'),
                '--database=' => __('migration registry 已登记的 PostgreSQL full mig_clone_*'),
                '--checkpoint=' => __('apply 输出的 checkpoint ID（verify/allowlist/rollback 必需）'),
                '--website=' => __('目标 Website ID；0 是合法系统默认站点'),
                '-h, --help' => __('显示本帮助'),
            ],
            [],
            [
                'php bin/w commerce:migrate-p4d-customer-asset help',
                'php bin/w mig:foundation clone-create --mode=full --purpose=p4dasset',
                'php bin/w commerce:migrate-p4d-customer-asset preflight --database=mig_clone_p4dasset_... --website=0',
                'php bin/w commerce:migrate-p4d-customer-asset apply --database=mig_clone_p4dasset_... --website=0',
                'php bin/w commerce:migrate-p4d-customer-asset verify --database=mig_clone_p4dasset_... --checkpoint=p4dasset-...',
                'php bin/w commerce:migrate-p4d-customer-asset allowlist --database=mig_clone_p4dasset_... --checkpoint=p4dasset-... --website=0',
                'php bin/w commerce:migrate-p4d-customer-asset rollback --database=mig_clone_p4dasset_... --checkpoint=p4dasset-...',
            ],
        );
    }

    /**
     * @param list<mixed> $args
     * @return array{
     *   action:string,
     *   database:string,
     *   checkpoint:string,
     *   website:?int,
     *   unknown_action:string
     * }
     */
    private function parse(array $args): array
    {
        $parsed = [
            'action' => '',
            'database' => trim((string) ($args['database'] ?? $args['db'] ?? '')),
            'checkpoint' => trim((string) ($args['checkpoint'] ?? '')),
            'website' => isset($args['website'])
                && ctype_digit((string) $args['website'])
                ? (int) $args['website']
                : null,
            'unknown_action' => '',
        ];
        $args = array_values(array_filter(
            $args,
            static fn (mixed $key): bool => is_int($key),
            ARRAY_FILTER_USE_KEY,
        ));
        foreach ($args as $index => $arg) {
            $raw = trim((string) $arg);
            $lower = strtolower($raw);
            if ($lower === 'commerce:migrate-p4d-customer-asset') {
                continue;
            }
            if (in_array(
                $lower,
                ['help', '-h', '--help', 'preflight', 'apply', 'verify', 'allowlist', 'rollback'],
                true,
            )) {
                $parsed['action'] = in_array($lower, ['-h', '--help'], true)
                    ? 'help'
                    : $lower;
                continue;
            }
            foreach (['database', 'checkpoint', 'website'] as $name) {
                $prefix = '--' . $name . '=';
                if (str_starts_with($lower, $prefix)) {
                    $this->assignOption(
                        $parsed,
                        $name,
                        substr($raw, strlen($prefix)),
                    );
                    continue 2;
                }
                if ($lower === '--' . $name && isset($args[$index + 1])) {
                    $this->assignOption(
                        $parsed,
                        $name,
                        (string) $args[$index + 1],
                    );
                    $args[$index + 1] = '';
                    continue 2;
                }
            }
            if (str_starts_with($lower, '--db=')) {
                $parsed['database'] = trim(substr($raw, 5));
                continue;
            }
            if ($raw !== ''
                && !str_starts_with($raw, '--')
                && $parsed['unknown_action'] === ''
            ) {
                $parsed['unknown_action'] = $raw;
            }
        }

        return $parsed;
    }

    /** @param array<string,mixed> $parsed */
    private function assignOption(array &$parsed, string $name, string $value): void
    {
        $value = trim($value);
        if ($name === 'website') {
            $parsed[$name] = $value !== '' && ctype_digit($value)
                ? (int) $value
                : null;
            return;
        }
        $parsed[$name] = $value;
    }

    private function requireWebsite(mixed $value): int
    {
        if (!is_int($value)) {
            throw new \InvalidArgumentException(
                CustomerAssetMigrationService::ERROR_SCOPE
                . ': pass --website=N',
            );
        }

        return $value;
    }

    /** @return array<string,mixed> */
    private function target(string $database): array
    {
        $database = strtolower(trim($database));
        if ($database === '') {
            throw new \InvalidArgumentException(
                CustomerAssetMigrationService::ERROR_SHARED_DB
                . ': pass --database=mig_clone_*',
            );
        }
        $envPath = (defined('BP') ? BP : dirname(__DIR__, 6)) . '/app/etc/env.php';
        $env = is_file($envPath) ? include $envPath : [];
        if (!is_array($env)) {
            $env = [];
        }
        $db = $env['db']['master'] ?? $env['db'] ?? [];
        if (!is_array($db)) {
            $db = [];
        }

        return [
            'type' => (string) ($db['type'] ?? 'pgsql'),
            'hostname' => (string) ($db['hostname'] ?? '127.0.0.1'),
            'hostport' => (string) ($db['hostport'] ?? '5432'),
            'database' => $database,
            'username' => (string) ($db['username'] ?? ''),
            'password' => (string) ($db['password'] ?? ''),
            'prefix' => (string) ($db['prefix'] ?? ''),
        ];
    }
}

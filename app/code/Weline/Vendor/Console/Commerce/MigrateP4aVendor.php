<?php

declare(strict_types=1);

namespace Weline\Vendor\Console\Commerce;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Vendor\Service\VendorMigrationService;

/**
 * MIG-P4A: registered full clone -> checkpoint -> shadow -> allowlist.
 */
class MigrateP4aVendor extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): int
    {
        $printing = ObjectManager::getInstance(Printing::class);
        $parsed = $this->parse($args);
        $action = $parsed['action'];

        if ($action === '' || $action === 'help') {
            $help = $this->help();
            $encoded = is_array($help)
                ? (json_encode($help, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}')
                : (string) $help;
            $printing->printing($encoded, 'success');

            return 0;
        }

        try {
            $target = $this->target((string) $parsed['database']);
            /** @var VendorMigrationService $migration */
            $migration = ObjectManager::getInstance(VendorMigrationService::class);
            $result = match ($action) {
                'preflight' => $migration->preflight(
                    $target,
                    $this->requireScopePart($parsed['website'], 'website'),
                    $this->requireScopePart($parsed['store'], 'store'),
                ),
                'apply' => $migration->apply(
                    $target,
                    $this->requireScopePart($parsed['website'], 'website'),
                    $this->requireScopePart($parsed['store'], 'store'),
                ),
                'verify' => $migration->verify(
                    $target,
                    (string) $parsed['checkpoint'],
                ),
                'allowlist' => $migration->allowlist(
                    $target,
                    (string) $parsed['checkpoint'],
                    $this->requireScopePart($parsed['website'], 'website'),
                    $this->requireScopePart($parsed['store'], 'store'),
                ),
                'rollback' => $migration->rollbackToModeOff(
                    $target,
                    (string) $parsed['checkpoint'],
                ),
            };
        } catch (\Throwable $exception) {
            $result = [
                'ok' => false,
                'phase' => VendorMigrationService::PHASE,
                'error' => $exception->getMessage(),
            ];
        }

        $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}';
        $ok = !array_key_exists('ok', $result) || $result['ok'] === true;
        if (isset($result['error'])) {
            $ok = false;
        }
        $printing->printing(
            "MIG-P4A {$action}: " . $encoded,
            $ok ? 'success' : 'error',
        );

        return $ok ? 0 : 2;
    }

    public function tip(): string
    {
        return (string) __(
            'MIG-P4A：Vendor 持久事实 shadow 到精确 Website/Store allowlist（仅登记的 full clone）',
        );
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'commerce:migrate-p4a-vendor',
            $this->tip(),
            [
                'help' => __('打印帮助'),
                'preflight' => __('在 full clone 只读清点 Vendor binding/snapshot/payout/reversal'),
                'apply' => __('冻结 checkpoint 与 shadow 报告；保持 shadow，不写造业务事实'),
                'verify' => __('新进程重载 checkpoint 并重算行 hash、守恒报告与 rollout'),
                'allowlist' => __('fresh verify 后仅放量目标 dev/test Website/Store'),
                'rollback' => __('按 checkpoint mode off，保留 binding/snapshot/既有结算义务'),
                '--database=' => __('migration registry 已登记的 full mig_clone_*'),
                '--checkpoint=' => __('apply 输出的 checkpoint ID（verify/allowlist/rollback 必需）'),
                '--website=' => __('目标 Website ID；0 是合法系统默认站点'),
                '--store=' => __('目标 dev/test Store ID，必须 >=1'),
                '-h, --help' => __('显示本帮助'),
            ],
            [],
            [
                'php bin/w commerce:migrate-p4a-vendor help',
                'php bin/w mig:foundation clone-create --mode=full --purpose=p4avendor',
                'php bin/w commerce:migrate-p4a-vendor preflight --database=mig_clone_p4avendor_... --website=0 --store=1',
                'php bin/w commerce:migrate-p4a-vendor apply --database=mig_clone_p4avendor_... --website=0 --store=1',
                'php bin/w commerce:migrate-p4a-vendor verify --database=mig_clone_p4avendor_... --checkpoint=p4avendor-...',
                'php bin/w commerce:migrate-p4a-vendor allowlist --database=mig_clone_p4avendor_... --checkpoint=p4avendor-... --website=0 --store=1',
                'php bin/w commerce:migrate-p4a-vendor rollback --database=mig_clone_p4avendor_... --checkpoint=p4avendor-...',
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
     *   store:?int
     * }
     */
    private function parse(array $args): array
    {
        $parsed = [
            'action' => '',
            'database' => '',
            'checkpoint' => '',
            'website' => null,
            'store' => null,
        ];
        foreach ($args as $index => $arg) {
            $raw = trim((string) $arg);
            $lower = strtolower($raw);
            if (in_array(
                $lower,
                ['help', 'preflight', 'apply', 'verify', 'allowlist', 'rollback'],
                true,
            )) {
                $parsed['action'] = $lower;
                continue;
            }
            foreach (['database', 'checkpoint', 'website', 'store'] as $name) {
                $prefix = '--' . $name . '=';
                if (str_starts_with($lower, $prefix)) {
                    $this->assignOption($parsed, $name, substr($raw, strlen($prefix)));
                    continue 2;
                }
                if ($lower === '--' . $name && isset($args[$index + 1])) {
                    $this->assignOption($parsed, $name, (string) $args[$index + 1]);
                    continue 2;
                }
            }
            if (str_starts_with($lower, '--db=')) {
                $parsed['database'] = trim(substr($raw, 5));
            }
        }

        return $parsed;
    }

    /** @param array<string,mixed> $parsed */
    private function assignOption(array &$parsed, string $name, string $value): void
    {
        $value = trim($value);
        if (in_array($name, ['website', 'store'], true)) {
            $parsed[$name] = $value !== '' && ctype_digit($value) ? (int) $value : null;
            return;
        }
        $parsed[$name] = $value;
    }

    private function requireScopePart(mixed $value, string $name): int
    {
        if (!is_int($value)) {
            throw new \InvalidArgumentException(
                VendorMigrationService::ERROR_SCOPE . ': pass --' . $name . '=N',
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
                VendorMigrationService::ERROR_SHARED_DB . ': pass --database=mig_clone_*',
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

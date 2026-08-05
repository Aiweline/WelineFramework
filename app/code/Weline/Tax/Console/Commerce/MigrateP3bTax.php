<?php

declare(strict_types=1);

namespace Weline\Tax\Console\Commerce;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Tax\Service\TaxMigrationService;

/**
 * MIG-P3B: Tax shadow -> fresh verify -> exact Scope allowlist.
 *
 * Every database action requires a registry-approved full mig_clone_*.
 * verify/allowlist/rollback also require apply's checkpoint.
 */
class MigrateP3bTax extends CommandAbstract
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
                : (string)$help;
            $printing->printing($encoded, 'success');

            return 0;
        }

        try {
            $target = $this->target((string)$parsed['database']);
            /** @var TaxMigrationService $migration */
            $migration = ObjectManager::getInstance(TaxMigrationService::class);
            $result = match ($action) {
                'preflight' => $migration->preflight(
                    $target,
                    $this->requireScopePart($parsed['website'], 'website'),
                    $this->requireScopePart($parsed['store'], 'store'),
                    $this->requireScopePart($parsed['channel'], 'channel'),
                ),
                'apply' => $migration->apply(
                    $target,
                    $this->requireScopePart($parsed['website'], 'website'),
                    $this->requireScopePart($parsed['store'], 'store'),
                    $this->requireScopePart($parsed['channel'], 'channel'),
                ),
                'verify' => $migration->verify($target, (string)$parsed['checkpoint']),
                'allowlist' => $migration->allowlist(
                    $target,
                    (string)$parsed['checkpoint'],
                    $this->requireScopePart($parsed['website'], 'website'),
                    $this->requireScopePart($parsed['store'], 'store'),
                    $this->requireScopePart($parsed['channel'], 'channel'),
                ),
                'rollback' => $migration->rollbackToModeOff(
                    $target,
                    (string)$parsed['checkpoint'],
                ),
            };
        } catch (\Throwable $exception) {
            $result = [
                'ok' => false,
                'phase' => TaxMigrationService::PHASE,
                'error' => $exception->getMessage(),
            ];
        }

        $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}';
        $ok = !array_key_exists('ok', $result) || $result['ok'] === true;
        if (isset($result['error'])) {
            $ok = false;
        }
        $printing->printing("MIG-P3B {$action}: " . $encoded, $ok ? 'success' : 'error');

        return $ok ? 0 : 2;
    }

    public function tip(): string
    {
        return (string)__(
            'MIG-P3B：Tax shadow 到精确 Scope allowlist（仅登记的 full clone）',
        );
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'commerce:migrate-p3b-tax',
            $this->tip(),
            [
                'help' => __('打印帮助'),
                'preflight' => __('在 full clone 只读清点 100 个持久化报价事实、规则快照与 rollout'),
                'apply' => __('checkpoint 后执行 current-vs-frozen shadow compare 并保存同版本 LKG；保持 shadow'),
                'verify' => __('新进程重载 checkpoint，重算同一观察窗并复核 report/LKG'),
                'allowlist' => __('fresh verify 成功后仅放量指定 Website/Store/Channel'),
                'rollback' => __('按 checkpoint 持久化 mode off；保留 LKG、TaxSnapshot 与审计事实'),
                '--database=' => __('migration registry 已登记的 full mig_clone_*'),
                '--checkpoint=' => __('apply 输出的 checkpoint ID（verify/allowlist/rollback 必需）'),
                '--website=' => __('目标 Website ID；0 是合法系统默认站点'),
                '--store=' => __('目标 Store ID，必须 >=1'),
                '--channel=' => __('目标 Channel ID，必须 >=1'),
                '-h, --help' => __('显示本帮助'),
            ],
            [],
            [
                'php bin/w commerce:migrate-p3b-tax help',
                'php bin/w mig:foundation clone-create --mode=full --purpose=p3btax',
                'php bin/w commerce:migrate-p3b-tax preflight --database=mig_clone_p3btax_... --website=0 --store=1 --channel=1',
                'php bin/w commerce:migrate-p3b-tax apply --database=mig_clone_p3btax_... --website=0 --store=1 --channel=1',
                'php bin/w commerce:migrate-p3b-tax verify --database=mig_clone_p3btax_... --checkpoint=p3btax-...',
                'php bin/w commerce:migrate-p3b-tax allowlist --database=mig_clone_p3btax_... --checkpoint=p3btax-... --website=0 --store=1 --channel=1',
                'php bin/w commerce:migrate-p3b-tax rollback --database=mig_clone_p3btax_... --checkpoint=p3btax-...',
            ],
        );
    }

    /**
     * @param list<mixed> $args
     * @return array{
     *     action:string,
     *     database:string,
     *     checkpoint:string,
     *     website:?int,
     *     store:?int,
     *     channel:?int
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
            'channel' => null,
        ];
        foreach ($args as $index => $arg) {
            $raw = trim((string)$arg);
            $lower = strtolower($raw);
            if (in_array(
                $lower,
                ['help', 'preflight', 'apply', 'verify', 'allowlist', 'rollback'],
                true,
            )) {
                $parsed['action'] = $lower;
                continue;
            }
            foreach (['database', 'checkpoint', 'website', 'store', 'channel'] as $name) {
                $prefix = '--' . $name . '=';
                if (str_starts_with($lower, $prefix)) {
                    $this->assignOption($parsed, $name, substr($raw, strlen($prefix)));
                    continue 2;
                }
                if ($lower === '--' . $name && isset($args[$index + 1])) {
                    $this->assignOption($parsed, $name, (string)$args[$index + 1]);
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
        if (in_array($name, ['website', 'store', 'channel'], true)) {
            $parsed[$name] = $value !== '' && ctype_digit($value) ? (int)$value : null;
            return;
        }
        $parsed[$name] = $value;
    }

    private function requireScopePart(mixed $value, string $name): int
    {
        if (!is_int($value)) {
            throw new \InvalidArgumentException(
                'mig_p3b_tax_scope_tuple_required: pass --' . $name . '=N',
            );
        }

        return $value;
    }

    /** @return array<string,mixed> */
    private function target(string $database): array
    {
        $database = trim($database);
        if ($database === '') {
            throw new \InvalidArgumentException(
                TaxMigrationService::ERROR_SHARED_DB . ': pass --database=mig_clone_*',
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
            'type' => (string)($db['type'] ?? 'pgsql'),
            'hostname' => (string)($db['hostname'] ?? '127.0.0.1'),
            'hostport' => (string)($db['hostport'] ?? '5432'),
            'database' => $database,
            'username' => (string)($db['username'] ?? ''),
            'password' => (string)($db['password'] ?? ''),
            'prefix' => (string)($db['prefix'] ?? ''),
        ];
    }
}

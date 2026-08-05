<?php

declare(strict_types=1);

namespace Weline\Subscription\Console\Commerce;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Subscription\Service\SubscriptionMigrationService;

/**
 * MIG-P4B: registered full clone -> checkpoint -> backfill -> allowlist.
 */
class MigrateP4bSubscription extends CommandAbstract
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
            /** @var SubscriptionMigrationService $migration */
            $migration = ObjectManager::getInstance(SubscriptionMigrationService::class);
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
                'phase' => SubscriptionMigrationService::PHASE,
                'error' => $exception->getMessage(),
            ];
        }

        $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}';
        $ok = !array_key_exists('ok', $result) || $result['ok'] === true;
        if (isset($result['error'])) {
            $ok = false;
        }
        $printing->printing(
            "MIG-P4B {$action}: " . $encoded,
            $ok ? 'success' : 'error',
        );

        return $ok ? 0 : 2;
    }

    public function tip(): string
    {
        return (string) __(
            'MIG-P4B：Subscription 周期水位与 scheduler allowlist（仅登记的 full clone）',
        );
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'commerce:migrate-p4b-subscription',
            $this->tip(),
            [
                'help' => __('打印帮助'),
                'preflight' => __('在 full clone 只读清点 Subscription/Period/Attempt/Watermark/Lease'),
                'apply' => __('写前 checkpoint，只补 gap Period/watermark 并保持 shadow'),
                'verify' => __('新进程重载 checkpoint，重算事实 hash、周期连续性与水位'),
                'allowlist' => __('fresh verify 后仅放量 checkpoint 冻结的 Website scheduler'),
                'rollback' => __('按 checkpoint mode off，保留既有 Period/Order/Payment 义务'),
                '--database=' => __('migration registry 已登记的 full mig_clone_*'),
                '--checkpoint=' => __('apply 输出的 checkpoint ID（verify/allowlist/rollback 必需）'),
                '--website=' => __('目标 Website ID；0 是合法系统默认站点'),
                '-h, --help' => __('显示本帮助'),
            ],
            [],
            [
                'php bin/w commerce:migrate-p4b-subscription help',
                'php bin/w mig:foundation clone-create --mode=full --purpose=p4bsubscription',
                'php bin/w commerce:migrate-p4b-subscription preflight --database=mig_clone_p4bsubscription_... --website=0',
                'php bin/w commerce:migrate-p4b-subscription apply --database=mig_clone_p4bsubscription_... --website=0',
                'php bin/w commerce:migrate-p4b-subscription verify --database=mig_clone_p4bsubscription_... --checkpoint=p4bsub-...',
                'php bin/w commerce:migrate-p4b-subscription allowlist --database=mig_clone_p4bsubscription_... --checkpoint=p4bsub-... --website=0',
                'php bin/w commerce:migrate-p4b-subscription rollback --database=mig_clone_p4bsubscription_... --checkpoint=p4bsub-...',
            ],
        );
    }

    /**
     * @param list<mixed> $args
     * @return array{action:string,database:string,checkpoint:string,website:?int}
     */
    private function parse(array $args): array
    {
        $parsed = [
            'action' => '',
            'database' => '',
            'checkpoint' => '',
            'website' => null,
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
            foreach (['database', 'checkpoint', 'website'] as $name) {
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
        if ($name === 'website') {
            $parsed[$name] = $value !== '' && ctype_digit($value) ? (int) $value : null;
            return;
        }
        $parsed[$name] = $value;
    }

    private function requireWebsite(mixed $value): int
    {
        if (!is_int($value)) {
            throw new \InvalidArgumentException(
                SubscriptionMigrationService::ERROR_SCOPE . ': pass --website=N',
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
                SubscriptionMigrationService::ERROR_SHARED_DB
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

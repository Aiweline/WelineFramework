<?php

declare(strict_types=1);

namespace Weline\Search\Console\Commerce;

use Weline\Framework\Console\CommandAbstract;
use Weline\Framework\Console\CommandHelper;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Output\Cli\Printing;
use Weline\Search\Service\SearchMigrationService;

/**
 * MIG-P3C: full build -> shadow -> fresh verify -> alias CAS allowlist.
 *
 * Every database action requires a registry-approved full mig_clone_*.
 */
class MigrateP3cSearch extends CommandAbstract
{
    public function execute(array $args = [], array $data = []): int
    {
        $printing = ObjectManager::getInstance(Printing::class);
        $parsed = $this->parse($args);
        $action = $parsed['action'];

        if ($action === '' || $action === 'help') {
            $help = $this->help();
            $encoded = \is_array($help)
                ? (\json_encode($help, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}')
                : (string)$help;
            $printing->printing($encoded, 'success');

            return 0;
        }

        try {
            $target = $this->target((string)$parsed['database']);
            /** @var SearchMigrationService $migration */
            $migration = ObjectManager::getInstance(SearchMigrationService::class);
            $result = match ($action) {
                'preflight' => $migration->preflight(
                    $target,
                    $this->requiredInt($parsed['website'], 'website', 0),
                    $this->requiredInt($parsed['store'], 'store', 1),
                    $this->requiredInt($parsed['channel'], 'channel', 1),
                    $this->requiredString($parsed['locale'], 'locale'),
                    $this->requiredString($parsed['currency'], 'currency'),
                ),
                'apply' => $migration->apply(
                    $target,
                    $this->requiredInt($parsed['website'], 'website', 0),
                    $this->requiredInt($parsed['store'], 'store', 1),
                    $this->requiredInt($parsed['channel'], 'channel', 1),
                    $this->requiredString($parsed['locale'], 'locale'),
                    $this->requiredString($parsed['currency'], 'currency'),
                ),
                'verify' => $migration->verify(
                    $target,
                    (string)$parsed['checkpoint'],
                ),
                'allowlist' => $migration->allowlist(
                    $target,
                    (string)$parsed['checkpoint'],
                    $this->requiredInt($parsed['website'], 'website', 0),
                    $this->requiredInt($parsed['store'], 'store', 1),
                    $this->requiredInt($parsed['channel'], 'channel', 1),
                ),
                'rollback' => $migration->rollbackToModeOff(
                    $target,
                    (string)$parsed['checkpoint'],
                ),
            };
        } catch (\Throwable $exception) {
            $result = [
                'ok' => false,
                'phase' => SearchMigrationService::PHASE,
                'error' => $exception->getMessage(),
            ];
        }

        $encoded = \json_encode(
            $result,
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT,
        ) ?: '{}';
        $ok = (!\array_key_exists('ok', $result) || $result['ok'] === true)
            && !isset($result['error']);
        $printing->printing(
            "MIG-P3C {$action}: " . $encoded,
            $ok ? 'success' : 'error',
        );

        return $ok ? 0 : 2;
    }

    public function tip(): string
    {
        return (string)__(
            'MIG-P3C：Search full clone shadow 到持久 alias CAS allowlist',
        );
    }

    public function help(): array|string
    {
        return CommandHelper::formatHelp(
            'commerce:migrate-p3c-search',
            $this->tip(),
            [
                'help' => __('打印帮助'),
                'preflight' => __('在登记 full clone 只读清点 Product/Search/alias/rollout 证据'),
                'apply' => __('checkpoint 后全量重建并追平水位；保持 shadow 与 direct alias'),
                'verify' => __('新进程重载 checkpoint 并重算 Product/Search/shadow 证据'),
                'allowlist' => __('fresh verify 后执行逐 Website alias CAS 与精确 Scope allowlist'),
                'rollback' => __('按 checkpoint 持久化 mode off 与 direct alias；保留索引事实'),
                '--database=' => __('migration registry 已登记的 full mig_clone_*'),
                '--checkpoint=' => __('apply 输出的 checkpoint ID（verify/allowlist/rollback 必需）'),
                '--website=' => __('目标 Website ID；0 是合法系统默认站点'),
                '--store=' => __('目标 Store ID，必须 >=1'),
                '--channel=' => __('目标 Channel ID，必须 >=1'),
                '--locale=' => __('shadow 观察窗 locale（preflight/apply 必需）'),
                '--currency=' => __('shadow 观察窗 currency（preflight/apply 必需）'),
                '-h, --help' => __('显示本帮助'),
            ],
            [],
            [
                'php bin/w commerce:migrate-p3c-search help',
                'php bin/w mig:foundation clone-create --mode=full --purpose=p3csearch',
                'php bin/w commerce:migrate-p3c-search preflight --database=mig_clone_p3csearch_... --website=0 --store=1 --channel=1 --locale=zh_Hans_CN --currency=CNY',
                'php bin/w commerce:migrate-p3c-search apply --database=mig_clone_p3csearch_... --website=0 --store=1 --channel=1 --locale=zh_Hans_CN --currency=CNY',
                'php bin/w commerce:migrate-p3c-search verify --database=mig_clone_p3csearch_... --checkpoint=p3csearch-...',
                'php bin/w commerce:migrate-p3c-search allowlist --database=mig_clone_p3csearch_... --checkpoint=p3csearch-... --website=0 --store=1 --channel=1',
                'php bin/w commerce:migrate-p3c-search rollback --database=mig_clone_p3csearch_... --checkpoint=p3csearch-...',
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
     *   store:?int,
     *   channel:?int,
     *   locale:string,
     *   currency:string
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
            'locale' => '',
            'currency' => '',
        ];
        foreach ($args as $index => $arg) {
            $raw = \trim((string)$arg);
            $lower = \strtolower($raw);
            if (\in_array(
                $lower,
                ['help', 'preflight', 'apply', 'verify', 'allowlist', 'rollback'],
                true,
            )) {
                $parsed['action'] = $lower;
                continue;
            }
            foreach ([
                'database',
                'checkpoint',
                'website',
                'store',
                'channel',
                'locale',
                'currency',
            ] as $name) {
                $prefix = '--' . $name . '=';
                if (\str_starts_with($lower, $prefix)) {
                    $this->assignOption($parsed, $name, \substr($raw, \strlen($prefix)));
                    continue 2;
                }
                if ($lower === '--' . $name && isset($args[$index + 1])) {
                    $this->assignOption($parsed, $name, (string)$args[$index + 1]);
                    continue 2;
                }
            }
            if (\str_starts_with($lower, '--db=')) {
                $parsed['database'] = \trim(\substr($raw, 5));
            }
        }

        return $parsed;
    }

    /** @param array<string,mixed> $parsed */
    private function assignOption(array &$parsed, string $name, string $value): void
    {
        $value = \trim($value);
        if (\in_array($name, ['website', 'store', 'channel'], true)) {
            $parsed[$name] = $value !== '' && \ctype_digit($value) ? (int)$value : null;

            return;
        }
        $parsed[$name] = $value;
    }

    private function requiredInt(mixed $value, string $name, int $minimum): int
    {
        if (!\is_int($value) || $value < $minimum) {
            throw new \InvalidArgumentException(
                SearchMigrationService::ERROR_SCOPE
                . ': pass --' . $name . '=N (minimum ' . $minimum . ')',
            );
        }

        return $value;
    }

    private function requiredString(mixed $value, string $name): string
    {
        $value = \trim((string)$value);
        if ($value === '') {
            throw new \InvalidArgumentException(
                SearchMigrationService::ERROR_SCOPE
                . ': pass --' . $name . '=VALUE',
            );
        }

        return $value;
    }

    /** @return array<string,mixed> */
    private function target(string $database): array
    {
        $database = \trim($database);
        if ($database === '') {
            throw new \InvalidArgumentException(
                SearchMigrationService::ERROR_SHARED_DB
                . ': pass --database=mig_clone_*',
            );
        }
        $envPath = (\defined('BP') ? BP : \dirname(__DIR__, 6)) . '/app/etc/env.php';
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
            'database' => $database,
            'username' => (string)($db['username'] ?? ''),
            'password' => (string)($db['password'] ?? ''),
            'prefix' => (string)($db['prefix'] ?? ''),
        ];
    }
}

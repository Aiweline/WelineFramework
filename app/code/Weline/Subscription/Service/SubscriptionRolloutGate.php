<?php

declare(strict_types=1);

namespace Weline\Subscription\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Database\ConnectionFactory;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;
use Weline\SystemConfig\Api\ConfigReader;
use Weline\SystemConfig\Api\ConfigStore;

/**
 * Durable Website-scoped Subscription rollout gate.
 */
final class SubscriptionRolloutGate implements CommerceRolloutGateInterface
{
    public const CAPABILITY = SubscriptionService::CAPABILITY;
    public const CONFIG_ROOT = 'commerce.rollout.subscription';
    public const CONFIG_MODE = self::CONFIG_ROOT . '.mode';
    public const CONFIG_ALLOWLIST = self::CONFIG_ROOT . '.allowlist';

    private const CONFIG_MODULE = 'Weline_Subscription';
    private const CONFIG_AREA = ConfigReader::area_FRONTEND;

    /** @var array{mode:mixed,allowlist:mixed}|null */
    private ?array $testingConfiguration = null;

    public function __construct(
        private ?ConfigStore $config = null,
    ) {
    }

    public static function forConnection(ConnectionFactory $connection): self
    {
        return new self(ConfigStore::forConnection($connection));
    }

    /** @param array{mode?:mixed,allowlist?:mixed} $configuration */
    public static function forTestingConfiguration(array $configuration = []): self
    {
        $gate = new self();
        $gate->testingConfiguration = [
            'mode' => $configuration['mode'] ?? self::MODE_OFF,
            'allowlist' => $configuration['allowlist'] ?? [],
        ];

        return $gate;
    }

    public function mode(string $capability): string
    {
        if ($capability !== self::CAPABILITY) {
            return self::MODE_OFF;
        }

        return $this->configuration()['mode'];
    }

    public function setMode(
        string $capability,
        string $mode,
        array $allowlistSubjects = [],
        string $productionOnToken = '',
    ): void {
        if ($capability !== self::CAPABILITY) {
            throw new \InvalidArgumentException('subscription_rollout_capability_invalid');
        }
        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException('commerce_rollout_unknown_mode:' . $mode);
        }
        if ($mode === self::MODE_ON && trim($productionOnToken) === '') {
            throw new \InvalidArgumentException('commerce_rollout_on_requires_explicit_token');
        }

        $rows = $this->normalizeSubjects($allowlistSubjects);
        if ($this->testingConfiguration !== null) {
            $this->testingConfiguration = ['mode' => $mode, 'allowlist' => $rows];
            return;
        }
        if ($this->envConfiguration() !== null) {
            throw new \RuntimeException('subscription_rollout_env_locked');
        }

        // Disable first so a partial configuration write remains fail closed.
        if ($mode === self::MODE_OFF || $mode === self::MODE_SHADOW) {
            $this->write(self::CONFIG_MODE, $mode);
            $this->write(self::CONFIG_ALLOWLIST, []);
        } else {
            $this->write(self::CONFIG_ALLOWLIST, $rows);
            $this->write(self::CONFIG_MODE, $mode);
        }

        $readback = $this->configuration();
        $expected = $this->normalizeConfiguration([
            'mode' => $mode,
            'allowlist' => $rows,
        ]);
        if ($readback['mode'] !== $expected['mode']
            || array_keys($readback['allowlist']) !== array_keys($expected['allowlist'])
        ) {
            throw new \RuntimeException('subscription_rollout_readback_mismatch');
        }
    }

    public function isShadow(string $capability): bool
    {
        return $this->mode($capability) === self::MODE_SHADOW;
    }

    public function isEffectivelyOn(string $capability, string $subject = ''): bool
    {
        if ($capability !== self::CAPABILITY) {
            return false;
        }
        $configuration = $this->configuration();

        return match ($configuration['mode']) {
            self::MODE_ON => true,
            self::MODE_ALLOWLIST => isset($configuration['allowlist'][$subject]),
            default => false,
        };
    }

    public function assertMutable(string $capability, string $subject = ''): void
    {
        if (!$this->isEffectivelyOn($capability, $subject)) {
            throw new \RuntimeException(
                'commerce_rollout_immutable:' . $this->mode($capability),
            );
        }
    }

    /**
     * @return array{
     *   mode:string,
     *   allowlist:array<string,true>,
     *   allowlist_rows:list<array{website_id:int}>,
     *   env_locked:bool
     * }
     */
    public function configuration(): array
    {
        if ($this->testingConfiguration !== null) {
            return $this->normalizeConfiguration($this->testingConfiguration) + [
                'env_locked' => false,
            ];
        }
        $env = $this->envConfiguration();
        if ($env !== null) {
            return $this->normalizeConfiguration($env) + ['env_locked' => true];
        }

        try {
            return $this->normalizeConfiguration([
                'mode' => $this->resolvedValue(self::CONFIG_MODE, self::MODE_OFF),
                'allowlist' => $this->resolvedValue(self::CONFIG_ALLOWLIST, []),
            ]) + ['env_locked' => false];
        } catch (\Throwable $exception) {
            throw new \RuntimeException(
                'subscription_rollout_config_unavailable',
                0,
                $exception,
            );
        }
    }

    /** @return array<string,mixed>|null */
    private function envConfiguration(): ?array
    {
        $missing = new \stdClass();
        $value = Env::get(self::CONFIG_ROOT, $missing);
        if ($value === $missing) {
            return null;
        }
        if (!is_array($value) || array_is_list($value)) {
            throw new \RuntimeException('subscription_rollout_config_invalid:env_root');
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $configuration
     * @return array{
     *   mode:string,
     *   allowlist:array<string,true>,
     *   allowlist_rows:list<array{website_id:int}>
     * }
     */
    private function normalizeConfiguration(array $configuration): array
    {
        $keys = array_keys($configuration);
        sort($keys, SORT_STRING);
        if ($keys !== ['allowlist', 'mode']) {
            throw new \RuntimeException('subscription_rollout_config_invalid:fields');
        }
        $mode = $configuration['mode'];
        if (!is_string($mode)
            || $mode !== trim($mode)
            || $mode !== strtolower($mode)
            || !in_array($mode, self::MODES, true)
        ) {
            throw new \RuntimeException('subscription_rollout_config_invalid:mode');
        }

        $rawRows = $configuration['allowlist'];
        if (!is_array($rawRows) || !array_is_list($rawRows)) {
            throw new \RuntimeException('subscription_rollout_config_invalid:allowlist');
        }
        $allowlist = [];
        $rows = [];
        foreach ($rawRows as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new \RuntimeException('subscription_rollout_config_invalid:allowlist_entry');
            }
            if (array_keys($row) !== ['website_id']) {
                throw new \RuntimeException('subscription_rollout_config_invalid:allowlist_fields');
            }
            $websiteId = $row['website_id'];
            if (!is_int($websiteId) || $websiteId < 0) {
                throw new \RuntimeException('subscription_rollout_config_invalid:allowlist_values');
            }
            $key = self::scopeKey($websiteId);
            if (isset($allowlist[$key])) {
                throw new \RuntimeException('subscription_rollout_config_invalid:allowlist_duplicate');
            }
            $allowlist[$key] = true;
            $rows[$key] = ['website_id' => $websiteId];
        }
        ksort($allowlist, SORT_STRING);
        ksort($rows, SORT_STRING);

        return [
            'mode' => $mode,
            'allowlist' => $allowlist,
            'allowlist_rows' => array_values($rows),
        ];
    }

    /** @param list<string> $subjects @return list<array{website_id:int}> */
    private function normalizeSubjects(array $subjects): array
    {
        $rows = [];
        foreach ($subjects as $subject) {
            $subject = trim((string) $subject);
            if (preg_match('/^website:(0|[1-9][0-9]*)$/D', $subject, $match) !== 1) {
                throw new \InvalidArgumentException(
                    'subscription_rollout_subject_invalid:' . $subject,
                );
            }
            $key = self::scopeKey((int) $match[1]);
            if (isset($rows[$key])) {
                throw new \InvalidArgumentException(
                    'subscription_rollout_subject_duplicate:' . $subject,
                );
            }
            $rows[$key] = ['website_id' => (int) $match[1]];
        }

        return array_values($rows);
    }

    public static function scopeKey(int $websiteId): string
    {
        if ($websiteId < 0) {
            throw new \InvalidArgumentException('subscription_rollout_website_invalid');
        }

        return 'website:' . $websiteId;
    }

    private function write(string $key, mixed $value): void
    {
        if (!$this->configStore()->setScopedConfig(
            $key,
            $value,
            self::CONFIG_MODULE,
            self::CONFIG_AREA,
            ConfigReader::SCOPE_GLOBAL,
            ConfigReader::LOCALE_DEFAULT,
        )) {
            throw new \RuntimeException('subscription_rollout_config_write_failed:' . $key);
        }
    }

    private function resolvedValue(string $key, mixed $default): mixed
    {
        $resolved = $this->configStore()->resolveConfig(
            $key,
            self::CONFIG_MODULE,
            self::CONFIG_AREA,
            ConfigReader::SCOPE_GLOBAL,
            ConfigReader::LOCALE_DEFAULT,
            $default,
        );

        return $resolved['value'] ?? $default;
    }

    private function configStore(): ConfigStore
    {
        return $this->config ??= new ConfigStore();
    }
}

<?php

declare(strict_types=1);

namespace Weline\Tax\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Database\ConnectionFactory;
use Weline\SystemConfig\Api\CommerceRolloutGateInterface;
use Weline\SystemConfig\Api\ConfigReader;
use Weline\SystemConfig\Api\ConfigStore;

/**
 * Durable Tax rollout gate backed by Env lock or global SystemConfig.
 */
final class TaxRolloutGate implements CommerceRolloutGateInterface
{
    public const CAPABILITY = 'tax';
    public const CONFIG_ROOT = 'commerce.rollout.tax';
    public const CONFIG_MODE = self::CONFIG_ROOT . '.mode';
    public const CONFIG_ALLOWLIST = self::CONFIG_ROOT . '.allowlist';
    public const CONFIG_SHADOW_SAMPLE_BP = self::CONFIG_ROOT . '.shadow_sample_bp';

    private const CONFIG_MODULE = 'Weline_Tax';
    private const CONFIG_AREA = ConfigReader::area_FRONTEND;
    private const DEFAULT_SHADOW_SAMPLE_BP = 10000;

    /** @var array{mode:mixed,allowlist:mixed,shadow_sample_bp:mixed}|null */
    private ?array $testingConfiguration = null;

    public function __construct(
        private ?ConfigStore $config = null,
    ) {
    }

    public static function forConnection(ConnectionFactory $connection): self
    {
        return new self(ConfigStore::forConnection($connection));
    }

    /**
     * @param array{mode?:mixed,allowlist?:mixed,shadow_sample_bp?:mixed} $configuration
     */
    public static function forTestingConfiguration(array $configuration = []): self
    {
        $gate = new self();
        $gate->testingConfiguration = [
            'mode' => $configuration['mode'] ?? self::MODE_OFF,
            'allowlist' => $configuration['allowlist'] ?? [],
            'shadow_sample_bp' => $configuration['shadow_sample_bp'] ?? self::DEFAULT_SHADOW_SAMPLE_BP,
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
            throw new \InvalidArgumentException('tax_rollout_capability_invalid');
        }
        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException('commerce_rollout_unknown_mode:' . $mode);
        }
        if ($mode === self::MODE_ON && trim($productionOnToken) === '') {
            throw new \InvalidArgumentException('commerce_rollout_on_requires_explicit_token');
        }

        $allowlist = $this->normalizeSubjects($allowlistSubjects);
        $next = $this->normalizeConfiguration([
            'mode' => $mode,
            'allowlist' => $allowlist,
            'shadow_sample_bp' => $this->configuration()['shadow_sample_bp'],
        ]);
        if ($this->testingConfiguration !== null) {
            $this->testingConfiguration = [
                'mode' => $next['mode'],
                'allowlist' => array_values($next['allowlist_rows']),
                'shadow_sample_bp' => $next['shadow_sample_bp'],
            ];
            return;
        }
        if ($this->envConfiguration() !== null) {
            throw new \RuntimeException('tax_rollout_env_locked');
        }

        if ($mode === self::MODE_OFF || $mode === self::MODE_SHADOW) {
            // Disable first, so a later allowlist cleanup failure stays fail-closed.
            $this->write(self::CONFIG_MODE, $mode);
            $this->write(self::CONFIG_ALLOWLIST, []);
        } else {
            // Materialize the exact allowlist before enabling a mutable mode.
            $this->write(self::CONFIG_ALLOWLIST, array_values($next['allowlist_rows']));
            $this->write(self::CONFIG_MODE, $mode);
        }
        $readback = $this->configuration();
        if ($readback['mode'] !== $mode
            || array_keys($readback['allowlist']) !== array_keys($next['allowlist'])
        ) {
            throw new \RuntimeException('tax_rollout_readback_mismatch');
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
            throw new \RuntimeException('commerce_rollout_immutable:' . $this->mode($capability));
        }
    }

    public function shadowSampleBasisPoints(): int
    {
        return $this->configuration()['shadow_sample_bp'];
    }

    /**
     * @return array{
     *     mode:string,
     *     allowlist:array<string,true>,
     *     allowlist_rows:array<string,array{website_id:int,store_id:int,channel_id:int}>,
     *     shadow_sample_bp:int,
     *     env_locked:bool
     * }
     */
    public function configuration(): array
    {
        if ($this->testingConfiguration !== null) {
            $normalized = $this->normalizeConfiguration($this->testingConfiguration);
            $normalized['env_locked'] = false;
            return $normalized;
        }
        $env = $this->envConfiguration();
        if ($env !== null) {
            $normalized = $this->normalizeConfiguration($env);
            $normalized['env_locked'] = true;
            return $normalized;
        }

        try {
            $normalized = $this->normalizeConfiguration([
                'mode' => $this->resolvedValue(self::CONFIG_MODE, self::MODE_OFF),
                'allowlist' => $this->resolvedValue(self::CONFIG_ALLOWLIST, []),
                'shadow_sample_bp' => $this->resolvedValue(
                    self::CONFIG_SHADOW_SAMPLE_BP,
                    self::DEFAULT_SHADOW_SAMPLE_BP,
                ),
            ]);
        } catch (\Throwable $exception) {
            throw new \RuntimeException('tax_rollout_config_unavailable', 0, $exception);
        }
        $normalized['env_locked'] = false;

        return $normalized;
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
            throw new \RuntimeException('tax_rollout_config_invalid:env_root_not_object');
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $configuration
     * @return array{
     *     mode:string,
     *     allowlist:array<string,true>,
     *     allowlist_rows:array<string,array{website_id:int,store_id:int,channel_id:int}>,
     *     shadow_sample_bp:int
     * }
     */
    private function normalizeConfiguration(array $configuration): array
    {
        $keys = array_keys($configuration);
        sort($keys, SORT_STRING);
        if ($keys !== ['allowlist', 'mode', 'shadow_sample_bp']) {
            throw new \RuntimeException('tax_rollout_config_invalid:fields');
        }
        $mode = $configuration['mode'];
        if (!is_string($mode)
            || $mode !== trim($mode)
            || $mode !== strtolower($mode)
            || !in_array($mode, self::MODES, true)
        ) {
            throw new \RuntimeException('tax_rollout_config_invalid:mode');
        }
        $sample = $configuration['shadow_sample_bp'];
        if (!is_int($sample) || $sample < 0 || $sample > 10000) {
            throw new \RuntimeException('tax_rollout_config_invalid:shadow_sample_bp');
        }
        $rawAllowlist = $configuration['allowlist'];
        if (!is_array($rawAllowlist) || !array_is_list($rawAllowlist)) {
            throw new \RuntimeException('tax_rollout_config_invalid:allowlist');
        }

        $allowlist = [];
        $rows = [];
        foreach ($rawAllowlist as $row) {
            if (!is_array($row) || array_is_list($row)) {
                throw new \RuntimeException('tax_rollout_config_invalid:allowlist_entry');
            }
            $rowKeys = array_keys($row);
            sort($rowKeys, SORT_STRING);
            if ($rowKeys !== ['channel_id', 'store_id', 'website_id']) {
                throw new \RuntimeException('tax_rollout_config_invalid:allowlist_fields');
            }
            $websiteId = $row['website_id'];
            $storeId = $row['store_id'];
            $channelId = $row['channel_id'];
            if (!is_int($websiteId) || $websiteId < 0
                || !is_int($storeId) || $storeId < 1
                || !is_int($channelId) || $channelId < 1
            ) {
                throw new \RuntimeException('tax_rollout_config_invalid:allowlist_values');
            }
            $key = self::tupleKey($websiteId, $storeId, $channelId);
            if (isset($allowlist[$key])) {
                throw new \RuntimeException('tax_rollout_config_invalid:allowlist_duplicate');
            }
            $allowlist[$key] = true;
            $rows[$key] = [
                'website_id' => $websiteId,
                'store_id' => $storeId,
                'channel_id' => $channelId,
            ];
        }
        ksort($allowlist, SORT_STRING);
        ksort($rows, SORT_STRING);

        return [
            'mode' => $mode,
            'allowlist' => $allowlist,
            'allowlist_rows' => $rows,
            'shadow_sample_bp' => $sample,
        ];
    }

    /**
     * @param list<string> $subjects
     * @return list<array{website_id:int,store_id:int,channel_id:int}>
     */
    private function normalizeSubjects(array $subjects): array
    {
        $rows = [];
        foreach ($subjects as $subject) {
            $subject = trim((string)$subject);
            if (preg_match('/^(0|[1-9][0-9]*):([1-9][0-9]*):([1-9][0-9]*)$/D', $subject, $match) !== 1) {
                throw new \InvalidArgumentException('tax_rollout_subject_invalid:' . $subject);
            }
            $row = [
                'website_id' => (int)$match[1],
                'store_id' => (int)$match[2],
                'channel_id' => (int)$match[3],
            ];
            $key = self::tupleKey($row['website_id'], $row['store_id'], $row['channel_id']);
            if (isset($rows[$key])) {
                throw new \InvalidArgumentException('tax_rollout_subject_duplicate:' . $subject);
            }
            $rows[$key] = $row;
        }

        return array_values($rows);
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
            throw new \RuntimeException('tax_rollout_config_write_failed:' . $key);
        }
    }

    public static function tupleKey(int $websiteId, int $storeId, int $channelId): string
    {
        return $websiteId . ':' . $storeId . ':' . $channelId;
    }

    private function configStore(): ConfigStore
    {
        return $this->config ??= new ConfigStore();
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
}

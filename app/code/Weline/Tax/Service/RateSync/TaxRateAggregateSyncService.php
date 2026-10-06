<?php

declare(strict_types=1);

namespace Weline\Tax\Service\RateSync;

use Weline\Framework\Manager\ObjectManager;
use Weline\SystemConfig\Api\ConfigReader;
use Weline\SystemConfig\Api\ConfigStore;
use Weline\Tax\Api\TaxRateRemoteProviderInterface;
use Weline\Tax\Service\TaxConfigurationAdminService;
use Weline\Tax\Service\TaxScopeConfig;

/**
 * Free-source union (max rate on conflict) + optional professional overlay → TaxRule upsert.
 */
final class TaxRateAggregateSyncService
{
    public const KEY_MODE = 'tax/ratesync/mode';
    public const KEY_FREE_PROVIDERS = 'tax/ratesync/free_providers';
    public const KEY_CRON_ENABLED = 'tax/ratesync/cron_enabled';
    public const KEY_PRO_ENABLED = 'tax/ratesync/professional/enabled';
    public const KEY_PRO_PROVIDER = 'tax/ratesync/professional/provider';
    public const KEY_LAST_RESULT = 'tax/ratesync/last_result';

    public const MODE_AUTO_FREE = 'auto_free';
    public const MODE_MANUAL_ONLY = 'manual_only';

    /** @var list<string> */
    public const DEFAULT_FREE_PROVIDERS = [
        StaticSeedTaxRateProvider::CODE,
        VatComplyEuTaxRateProvider::CODE,
    ];

    /** @var callable(array<string,mixed>):array{action:string,rule:array<string,mixed>}|null */
    private $upsertHook;

    /** @var array<string,mixed> */
    private array $configOverrides;

    /** @var list<array<string,mixed>> */
    private array $persistedResults = [];

    /**
     * @param callable(array<string,mixed>):array{action:string,rule:array<string,mixed>}|null $upsertHook
     * @param array<string,mixed> $configOverrides
     */
    public function __construct(
        private readonly TaxRateRemoteProviderRegistry $registry,
        private readonly ?TaxConfigurationAdminService $admin = null,
        private readonly ?ConfigReader $reader = null,
        private readonly ?ConfigStore $configStore = null,
        ?callable $upsertHook = null,
        array $configOverrides = [],
    ) {
        $this->upsertHook = $upsertHook;
        $this->configOverrides = $configOverrides;
    }

    /**
     * @param list<TaxRateRemoteProviderInterface> $providers
     * @param callable(array<string,mixed>):array{action:string,rule:array<string,mixed>} $upsert
     * @param array<string,mixed> $config
     */
    public static function forTesting(array $providers, callable $upsert, array $config = []): self
    {
        return new self(
            TaxRateRemoteProviderRegistry::forTesting($providers),
            null,
            null,
            null,
            $upsert,
            $config,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function sync(int $websiteId = 0, bool $force = false): array
    {
        $websiteId = max(0, $websiteId);
        $mode = strtolower(trim((string)$this->configGet(
            self::KEY_MODE,
            self::MODE_AUTO_FREE,
        )));
        if ($mode === '') {
            $mode = self::MODE_AUTO_FREE;
        }
        if (!$force && $mode === self::MODE_MANUAL_ONLY) {
            $result = [
                'ok' => true,
                'skipped' => true,
                'reason' => 'manual_only',
                'website_id' => $websiteId,
                'started_at' => gmdate('c'),
                'finished_at' => gmdate('c'),
            ];
            $this->persistLastResult($result);

            return $result;
        }

        $started = gmdate('c');
        $freeEnabled = $this->enabledFreeProviderCodes();
        $sourceStats = [];
        $failedSources = [];
        $freeCandidates = [];

        foreach ($this->registry->all() as $provider) {
            if ($provider->tier() !== TaxRateRemoteProviderInterface::TIER_FREE) {
                continue;
            }
            $code = strtolower(trim($provider->code()));
            if (!in_array($code, $freeEnabled, true)) {
                continue;
            }
            try {
                $rows = $provider->fetchCandidates($websiteId);
                $sourceStats[$code] = ['tier' => 'free', 'candidates' => count($rows), 'ok' => true];
                foreach ($rows as $row) {
                    if (is_array($row)) {
                        $freeCandidates[] = $row;
                    }
                }
            } catch (\Throwable $e) {
                $failedSources[] = [
                    'code' => $code,
                    'tier' => 'free',
                    'error' => $this->sanitizeError($e->getMessage()),
                ];
                $sourceStats[$code] = ['tier' => 'free', 'candidates' => 0, 'ok' => false];
            }
        }

        [$merged, $conflictMaxCount] = $this->unionMaxRate($freeCandidates);

        $proEnabled = in_array(
            $this->configGet(self::KEY_PRO_ENABLED, false),
            [true, 1, '1', 'true', 'on'],
            true,
        );
        $proOverlayCount = 0;
        if ($proEnabled) {
            $proCode = strtolower(trim((string)$this->configGet(
                self::KEY_PRO_PROVIDER,
                GenericHttpTaxRateProvider::CODE,
            )));
            if ($proCode === '') {
                $proCode = GenericHttpTaxRateProvider::CODE;
            }
            $pro = $this->registry->get($proCode);
            if ($pro instanceof TaxRateRemoteProviderInterface
                && $pro->tier() === TaxRateRemoteProviderInterface::TIER_PROFESSIONAL
            ) {
                try {
                    $proRows = $pro->fetchCandidates($websiteId);
                    $sourceStats[$proCode] = ['tier' => 'professional', 'candidates' => count($proRows), 'ok' => true];
                    foreach ($proRows as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $key = $this->candidateKey($row);
                        if ($key === null) {
                            continue;
                        }
                        $merged[$key] = $this->normalizeCandidate($row, $proCode);
                        ++$proOverlayCount;
                    }
                } catch (\Throwable $e) {
                    $failedSources[] = [
                        'code' => $proCode,
                        'tier' => 'professional',
                        'error' => $this->sanitizeError($e->getMessage()),
                    ];
                    $sourceStats[$proCode] = ['tier' => 'professional', 'candidates' => 0, 'ok' => false];
                }
            } else {
                $failedSources[] = [
                    'code' => $proCode,
                    'tier' => 'professional',
                    'error' => 'provider_unavailable',
                ];
            }
        }

        $created = 0;
        $updated = 0;
        $unchanged = 0;
        foreach ($merged as $candidate) {
            $result = $this->upsert([
                'website_id' => $websiteId,
                'class_code' => $candidate['class_code'],
                'jurisdiction_key' => $candidate['jurisdiction_key'],
                'rate_bps' => $candidate['rate_bps'],
                'enabled' => 1,
            ]);
            $action = (string)($result['action'] ?? '');
            if ($action === 'created') {
                ++$created;
            } elseif ($action === 'updated') {
                ++$updated;
            } else {
                ++$unchanged;
            }
        }

        $summary = [
            'ok' => true,
            'skipped' => false,
            'website_id' => $websiteId,
            'mode' => $mode,
            'force' => $force,
            'started_at' => $started,
            'finished_at' => gmdate('c'),
            'free_providers' => $freeEnabled,
            'source_stats' => $sourceStats,
            'failed_sources' => $failedSources,
            'conflict_max_count' => $conflictMaxCount,
            'professional_overlay_count' => $proOverlayCount,
            'merged_count' => count($merged),
            'rules_created' => $created,
            'rules_updated' => $updated,
            'rules_unchanged' => $unchanged,
        ];
        $this->persistLastResult($summary);

        return $summary;
    }

    /** @return array<string,mixed> */
    public function lastResult(): array
    {
        if ($this->persistedResults !== []) {
            return $this->persistedResults[array_key_last($this->persistedResults)] ?? [];
        }
        $raw = trim((string)$this->configGet(self::KEY_LAST_RESULT, ''));
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    public function cronEnabled(): bool
    {
        return in_array(
            $this->configGet(self::KEY_CRON_ENABLED, true),
            [true, 1, '1', 'true', 'on'],
            true,
        );
    }

    /**
     * @param list<array<string,mixed>> $candidates
     * @return array{0:array<string,array{jurisdiction_key:string,class_code:string,rate_bps:int,source:string}>,1:int}
     */
    private function unionMaxRate(array $candidates): array
    {
        $merged = [];
        $conflictMax = 0;
        foreach ($candidates as $row) {
            $key = $this->candidateKey($row);
            if ($key === null) {
                continue;
            }
            $normalized = $this->normalizeCandidate($row, (string)($row['source'] ?? 'unknown'));
            if (!isset($merged[$key])) {
                $merged[$key] = $normalized;
                continue;
            }
            $existing = $merged[$key];
            if ($normalized['rate_bps'] > $existing['rate_bps']) {
                $merged[$key] = $normalized;
                ++$conflictMax;
            } elseif ($normalized['rate_bps'] < $existing['rate_bps']) {
                ++$conflictMax;
            }
        }

        return [$merged, $conflictMax];
    }

    /** @param array<string,mixed> $row */
    private function candidateKey(array $row): ?string
    {
        $jurisdiction = strtoupper(trim((string)($row['jurisdiction_key'] ?? '')));
        $class = strtolower(trim((string)($row['class_code'] ?? 'standard')));
        if ($jurisdiction === '' || $class === '') {
            return null;
        }

        return $class . "\0" . $jurisdiction;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{jurisdiction_key:string,class_code:string,rate_bps:int,source:string}
     */
    private function normalizeCandidate(array $row, string $source): array
    {
        return [
            'jurisdiction_key' => strtoupper(trim((string)($row['jurisdiction_key'] ?? ''))),
            'class_code' => strtolower(trim((string)($row['class_code'] ?? 'standard'))),
            'rate_bps' => (int)($row['rate_bps'] ?? 0),
            'source' => $source !== '' ? $source : (string)($row['source'] ?? 'unknown'),
        ];
    }

    /** @return list<string> */
    private function enabledFreeProviderCodes(): array
    {
        $raw = $this->configGet(
            self::KEY_FREE_PROVIDERS,
            implode(',', self::DEFAULT_FREE_PROVIDERS),
        );
        if (is_array($raw)) {
            $parts = $raw;
        } else {
            $parts = preg_split('/[\s,;]+/', trim((string)$raw)) ?: [];
        }
        $out = [];
        foreach ($parts as $part) {
            $code = strtolower(trim((string)$part));
            if ($code === '' || in_array($code, $out, true)) {
                continue;
            }
            $out[] = $code;
        }

        return $out !== [] ? $out : self::DEFAULT_FREE_PROVIDERS;
    }

    private function configGet(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->configOverrides)) {
            return $this->configOverrides[$key];
        }
        if ($this->reader instanceof ConfigReader) {
            return $this->reader->get($key, TaxScopeConfig::MODULE, TaxScopeConfig::AREA, $default);
        }
        if ($this->upsertHook !== null) {
            return $default;
        }

        return (new ConfigReader())->get($key, TaxScopeConfig::MODULE, TaxScopeConfig::AREA, $default);
    }

    /**
     * @param array<string,mixed> $input
     * @return array{action:string,rule:array<string,mixed>}
     */
    private function upsert(array $input): array
    {
        if ($this->upsertHook !== null) {
            /** @var array{action:string,rule:array<string,mixed>} $out */
            $out = ($this->upsertHook)($input);

            return $out;
        }
        $admin = $this->admin;
        if (!$admin instanceof TaxConfigurationAdminService) {
            $admin = ObjectManager::getInstance(TaxConfigurationAdminService::class);
        }
        if (!$admin instanceof TaxConfigurationAdminService) {
            throw new \LogicException('tax_ratesync_admin_unavailable');
        }

        return $admin->upsertRule($input);
    }

    /** @param array<string,mixed> $result */
    private function persistLastResult(array $result): void
    {
        $this->persistedResults[] = $result;
        if ($this->upsertHook !== null && !$this->configStore instanceof ConfigStore) {
            return;
        }
        $store = $this->configStore ?? new ConfigStore();
        $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
        $store->setScopedConfig(
            self::KEY_LAST_RESULT,
            $encoded,
            TaxScopeConfig::MODULE,
            TaxScopeConfig::AREA,
            ConfigReader::SCOPE_GLOBAL,
            ConfigReader::LOCALE_DEFAULT,
            [
                'value_type' => 'string',
                'reason' => 'tax_ratesync',
            ],
        );
    }

    private function sanitizeError(string $message): string
    {
        $message = preg_replace('/Bearer\s+\S+/i', 'Bearer ***', $message) ?? $message;
        $message = preg_replace('/api[_-]?key["\']?\s*[:=]\s*["\']?[^"\'\s]+/i', 'api_key=***', $message) ?? $message;

        return mb_substr($message, 0, 240);
    }
}

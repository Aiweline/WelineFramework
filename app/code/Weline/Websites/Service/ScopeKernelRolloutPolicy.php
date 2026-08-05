<?php

declare(strict_types=1);

namespace Weline\Websites\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Runtime\FrontendWorkerScopeException;
use Weline\Framework\Service\Query\Value\FrontendWorkerScopeRolloutDecision;
use Weline\SystemConfig\Api\ConfigReader;

/**
 * Scope-kernel rollout policy backed by the program-wide commerce keys.
 *
 * Deployment env is an env-lock for the complete scope_kernel object. When it
 * is absent, the global SystemConfig values are read. Explicitly missing rows
 * default to off; a read failure or malformed value is fail-closed.
 */
final class ScopeKernelRolloutPolicy
{
    public const CONFIG_ROOT = 'commerce.rollout.scope_kernel';
    public const CONFIG_MODE = self::CONFIG_ROOT . '.mode';
    public const CONFIG_ALLOWLIST = self::CONFIG_ROOT . '.allowlist';
    public const CONFIG_SHADOW_SAMPLE_BP = self::CONFIG_ROOT . '.shadow_sample_bp';

    private const CONFIG_MODULE = 'Weline_Websites';
    private const CONFIG_AREA = ConfigReader::area_FRONTEND;
    private const DEFAULT_SHADOW_SAMPLE_BP = 10000;
    private const CONFIG_KEYS = ['allowlist', 'mode', 'shadow_sample_bp'];

    /** @var array{mode?:mixed,allowlist?:mixed,shadow_sample_bp?:mixed}|null */
    private ?array $configuration = null;

    /**
     * Runtime construction always reads Env/SystemConfig.  Do not expose an
     * optional array here: the legacy container normalizes an omitted nullable
     * array to [], which silently turns an explicit deployment rollout back to
     * the safe "off" defaults.
     */
    public function __construct(
        private readonly ConfigReader $configReader,
    ) {
    }

    /**
     * Deterministic control-plane validation without widening the runtime DI
     * constructor.
     *
     * @param array{mode?:mixed,allowlist?:mixed,shadow_sample_bp?:mixed} $configuration
     */
    public static function forConfiguration(ConfigReader $configReader, array $configuration): self
    {
        $policy = new self($configReader);
        $policy->configuration = $configuration;

        return $policy;
    }

    public function mode(): string
    {
        return $this->load()['mode'];
    }

    public function requiresBinding(string $requestScheme): bool
    {
        $scheme = $this->normalizeScheme($requestScheme);
        $mode = $this->load()['mode'];
        if (!\in_array(
            $mode,
            [
                FrontendWorkerScopeRolloutDecision::MODE_ALLOWLIST,
                FrontendWorkerScopeRolloutDecision::MODE_ON,
            ],
            true,
        )) {
            return false;
        }

        $this->assertHttps($scheme);
        return true;
    }

    public function offDecision(): FrontendWorkerScopeRolloutDecision
    {
        $decision = $this->offDecisionOrNull();
        if (!$decision instanceof FrontendWorkerScopeRolloutDecision) {
            throw new \LogicException('Scope kernel rollout is not off.');
        }

        return $decision;
    }

    public function offDecisionOrNull(): ?FrontendWorkerScopeRolloutDecision
    {
        $config = $this->load();
        if ($config['mode'] !== FrontendWorkerScopeRolloutDecision::MODE_OFF) {
            return null;
        }

        return new FrontendWorkerScopeRolloutDecision(
            $config['mode'],
            false,
            false,
            null,
            null,
            null,
            $config['shadow_sample_bp'],
            'mode_off',
        );
    }

    public function decide(
        int $websiteId,
        int $storeId,
        int $channelId,
        string $storeMode,
        string $requestScheme,
    ): FrontendWorkerScopeRolloutDecision {
        if ($websiteId < 0 || $storeId < 1 || $channelId < 1) {
            throw new FrontendWorkerScopeException(
                'rollout_scope_tuple_invalid',
                503,
                (string)__('Scope Kernel 切流三元组无效'),
            );
        }
        if (!\in_array($storeMode, ['normal', 'dev', 'test'], true)) {
            throw new FrontendWorkerScopeException(
                'rollout_store_mode_invalid',
                503,
                (string)__('Scope Kernel 切流店铺模式无效'),
            );
        }

        $scheme = $this->normalizeScheme($requestScheme);
        $config = $this->load();
        $mode = $config['mode'];
        $tuple = [$websiteId, $storeId, $channelId];

        if ($mode === FrontendWorkerScopeRolloutDecision::MODE_OFF) {
            return new FrontendWorkerScopeRolloutDecision(
                $mode,
                false,
                false,
                $websiteId,
                $storeId,
                $channelId,
                $config['shadow_sample_bp'],
                'mode_off',
            );
        }

        if ($mode === FrontendWorkerScopeRolloutDecision::MODE_SHADOW) {
            // Shadow is observation-only. It must never put the main Worker
            // request path behind a one-time bootstrap, because a bootstrap or
            // Catalog failure would then turn sampling into a traffic cutover.
            // Pure sampled comparisons must stay server-side; no client token
            // is authoritative in shadow.
            return new FrontendWorkerScopeRolloutDecision(
                $mode,
                false,
                false,
                $websiteId,
                $storeId,
                $channelId,
                $config['shadow_sample_bp'],
                $config['shadow_sample_bp'] > 0 ? 'shadow_observe_only' : 'shadow_sampling_disabled',
            );
        }

        if ($mode === FrontendWorkerScopeRolloutDecision::MODE_ALLOWLIST) {
            // Binding is mandatory for every HTTPS storefront in allowlist
            // mode. Otherwise an allowlisted page could delete its bootstrap
            // material and fall through to an unbound legacy QueryBin path.
            $this->assertHttps($scheme);
            if (!isset($config['allowlist'][$this->tupleKey($tuple)])) {
                return new FrontendWorkerScopeRolloutDecision(
                    $mode,
                    true,
                    false,
                    $websiteId,
                    $storeId,
                    $channelId,
                    $config['shadow_sample_bp'],
                    'scope_bound_not_allowlisted',
                );
            }
            if (!\in_array($storeMode, ['dev', 'test'], true)) {
                throw new FrontendWorkerScopeException(
                    'allowlist_requires_dev_test',
                    503,
                    (string)__('Scope Kernel 首次 allowlist 切流仅允许 dev/test 店铺'),
                );
            }
            return new FrontendWorkerScopeRolloutDecision(
                $mode,
                true,
                true,
                $websiteId,
                $storeId,
                $channelId,
                $config['shadow_sample_bp'],
                'scope_allowlisted',
            );
        }

        $this->assertHttps($scheme);
        return new FrontendWorkerScopeRolloutDecision(
            $mode,
            true,
            true,
            $websiteId,
            $storeId,
            $channelId,
            $config['shadow_sample_bp'],
            'mode_on',
        );
    }

    /**
     * @return array{
     *     mode:string,
     *     allowlist:array<string,true>,
     *     shadow_sample_bp:int
     * }
     */
    private function load(): array
    {
        if ($this->configuration !== null) {
            return $this->normalizeConfiguration($this->configuration);
        }

        $envMissing = new \stdClass();
        $env = Env::get(self::CONFIG_ROOT, $envMissing);
        if ($env !== $envMissing) {
            if (!\is_array($env) || \array_is_list($env)) {
                throw $this->invalidConfiguration('env_root_not_object');
            }
            return $this->normalizeConfiguration($env);
        }

        try {
            $mode = $this->configReader->getConfig(
                self::CONFIG_MODE,
                self::CONFIG_MODULE,
                self::CONFIG_AREA,
                FrontendWorkerScopeRolloutDecision::MODE_OFF,
                ConfigReader::SCOPE_GLOBAL,
                ConfigReader::LOCALE_DEFAULT,
            );
            $allowlist = $this->configReader->getConfig(
                self::CONFIG_ALLOWLIST,
                self::CONFIG_MODULE,
                self::CONFIG_AREA,
                [],
                ConfigReader::SCOPE_GLOBAL,
                ConfigReader::LOCALE_DEFAULT,
            );
            $shadowSample = $this->configReader->getConfig(
                self::CONFIG_SHADOW_SAMPLE_BP,
                self::CONFIG_MODULE,
                self::CONFIG_AREA,
                self::DEFAULT_SHADOW_SAMPLE_BP,
                ConfigReader::SCOPE_GLOBAL,
                ConfigReader::LOCALE_DEFAULT,
            );
        } catch (\Throwable $exception) {
            // Missing rows use the explicit safe defaults above. An actual
            // read failure must not silently turn allowlist/on back into an
            // unscoped legacy path.
            throw new FrontendWorkerScopeException(
                'rollout_config_unavailable',
                503,
                (string)__('Scope Kernel 切流配置暂不可用'),
                $exception,
            );
        }

        return $this->normalizeConfiguration([
            'mode' => $mode,
            'allowlist' => $allowlist,
            'shadow_sample_bp' => $shadowSample,
        ]);
    }

    /**
     * @param array<string, mixed> $configuration
     * @return array{mode:string,allowlist:array<string,true>,shadow_sample_bp:int}
     */
    private function normalizeConfiguration(array $configuration): array
    {
        $keys = \array_keys($configuration);
        \sort($keys, SORT_STRING);
        $unknown = \array_values(\array_diff($keys, self::CONFIG_KEYS));
        if ($unknown !== []) {
            throw $this->invalidConfiguration('unknown_keys');
        }

        $mode = $configuration['mode'] ?? FrontendWorkerScopeRolloutDecision::MODE_OFF;
        if (!\is_string($mode)
            || $mode !== \trim($mode)
            || $mode !== \strtolower($mode)
            || !\in_array($mode, FrontendWorkerScopeRolloutDecision::MODES, true)) {
            throw $this->invalidConfiguration('invalid_mode');
        }

        $shadowSample = $configuration['shadow_sample_bp'] ?? self::DEFAULT_SHADOW_SAMPLE_BP;
        if (!\is_int($shadowSample) || $shadowSample < 0 || $shadowSample > 10000) {
            throw $this->invalidConfiguration('invalid_shadow_sample_bp');
        }

        $rawAllowlist = $configuration['allowlist'] ?? [];
        if (!\is_array($rawAllowlist) || !\array_is_list($rawAllowlist)) {
            throw $this->invalidConfiguration('allowlist_not_list');
        }

        $allowlist = [];
        foreach ($rawAllowlist as $entry) {
            if (!\is_array($entry) || \array_is_list($entry)) {
                throw $this->invalidConfiguration('allowlist_entry_not_object');
            }
            $entryKeys = \array_keys($entry);
            \sort($entryKeys, SORT_STRING);
            if ($entryKeys !== ['channel_id', 'store_id', 'website_id']) {
                throw $this->invalidConfiguration('allowlist_entry_fields');
            }
            $websiteId = $entry['website_id'];
            $storeId = $entry['store_id'];
            $channelId = $entry['channel_id'];
            if (!\is_int($websiteId) || !\is_int($storeId) || !\is_int($channelId)
                || $websiteId < 0 || $storeId < 1 || $channelId < 1) {
                throw $this->invalidConfiguration('allowlist_entry_values');
            }
            $key = $this->tupleKey([$websiteId, $storeId, $channelId]);
            if (isset($allowlist[$key])) {
                throw $this->invalidConfiguration('allowlist_duplicate_tuple');
            }
            $allowlist[$key] = true;
        }

        return [
            'mode' => $mode,
            'allowlist' => $allowlist,
            'shadow_sample_bp' => $shadowSample,
        ];
    }

    /** @param array{0:int,1:int,2:int} $tuple */
    private function tupleKey(array $tuple): string
    {
        return $tuple[0] . ':' . $tuple[1] . ':' . $tuple[2];
    }

    private function normalizeScheme(string $scheme): string
    {
        $normalized = \strtolower(\trim($scheme));
        if ($normalized !== $scheme || !\in_array($normalized, ['http', 'https'], true)) {
            throw new FrontendWorkerScopeException(
                'request_scheme_invalid',
                400,
                (string)__('Scope Token 请求协议无效'),
            );
        }
        return $normalized;
    }

    private function assertHttps(string $scheme): void
    {
        if ($scheme !== 'https') {
            throw new FrontendWorkerScopeException(
                'scope_token_https_required',
                403,
                (string)__('Scope Token allowlist/on 切流仅允许 HTTPS'),
            );
        }
    }

    private function invalidConfiguration(string $detail): FrontendWorkerScopeException
    {
        return new FrontendWorkerScopeException(
            'rollout_config_invalid',
            503,
            (string)__('Scope Kernel 切流配置无效：%{1}', [$detail]),
        );
    }
}

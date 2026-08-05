<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Service;

use Weline\Framework\Http\Security\SecurityHeaderPolicyService;
use Weline\SystemConfig\Model\SystemConfig;

/**
 * Fail-closed activation guard for scoped security response-header settings.
 */
final class SecurityPolicyConfigGuard
{
    public const MODULE = 'Weline_Framework';
    public const AREA = SystemConfig::area_BACKEND;

    public const KEY_CSP = 'security.headers.csp';
    public const KEY_CSP_REPORT_ONLY = 'security.headers.csp_report_only';
    public const KEY_CORS_ORIGINS = 'security.headers.cors_origins';

    /** @var array<string, string> */
    private const POLICY_KEYS = [
        'csp' => self::KEY_CSP,
        'csp_report_only' => self::KEY_CSP_REPORT_ONLY,
        'cors_origins' => self::KEY_CORS_ORIGINS,
    ];

    public function __construct(
        private readonly SystemConfig $systemConfig,
        private readonly SecurityHeaderPolicyService $policyService,
    ) {
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string> $inheritKeys
     */
    public function assertMutation(
        string $module,
        string $area,
        string $scope,
        string $locale,
        array $values,
        array $inheritKeys,
    ): void {
        if (!$this->isSecurityMutation($module, $area, $values, $inheritKeys)) {
            return;
        }

        $candidate = $this->candidate($scope, $locale, $values, $inheritKeys);
        $this->policyService->assertCanActivate($candidate, $scope);
    }

    /**
     * Register an explicitly reviewed candidate before the matching config
     * mutation is allowed.
     *
     * @param array<string, mixed> $values
     * @param list<string> $inheritKeys
     * @return array{schema_version:string,scope_key:string,digest:string,verified_at:string}
     */
    public function registerLkg(
        string $scope,
        string $locale,
        array $values = [],
        array $inheritKeys = [],
    ): array {
        $scope = $this->systemConfig->normalizeScope($scope);
        $locale = $this->systemConfig->normalizeLocale($locale);
        $candidate = $this->candidate($scope, $locale, $values, $inheritKeys);

        return $this->policyService->registerLkg($candidate, $scope);
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string> $inheritKeys
     * @return array{csp:string,csp_report_only:string,cors_origins:string}
     */
    private function candidate(
        string $scope,
        string $locale,
        array $values,
        array $inheritKeys,
    ): array {
        $inherit = \array_fill_keys(\array_map('strval', $inheritKeys), true);
        $candidate = [];
        foreach (self::POLICY_KEYS as $policyKey => $configKey) {
            if (isset($inherit[$configKey])) {
                $value = $this->fallbackValue($configKey, $scope, $locale);
            } elseif (\array_key_exists($configKey, $values)) {
                $value = $values[$configKey];
            } else {
                $value = $this->systemConfig->getConfig(
                    key: $configKey,
                    module: self::MODULE,
                    area: self::AREA,
                    default: '',
                    scope: $scope,
                    locale: $locale,
                );
            }
            if (!\is_scalar($value) && $value !== null) {
                throw new \InvalidArgumentException('security_policy_value_invalid');
            }
            $candidate[$policyKey] = \trim((string)$value);
        }

        return $candidate;
    }

    private function fallbackValue(string $key, string $scope, string $locale): string
    {
        $fallbacks = $this->systemConfig->getFallbackScopes($scope);
        \array_shift($fallbacks);
        $parent = $fallbacks[0] ?? null;
        if ($parent === null) {
            return '';
        }

        return (string)$this->systemConfig->getConfig(
            key: $key,
            module: self::MODULE,
            area: self::AREA,
            default: '',
            scope: $parent,
            locale: $locale,
        );
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string> $inheritKeys
     */
    private function isSecurityMutation(
        string $module,
        string $area,
        array $values,
        array $inheritKeys,
    ): bool {
        if ($module !== self::MODULE || $area !== self::AREA) {
            return false;
        }
        $changed = \array_unique([
            ...\array_map('strval', \array_keys($values)),
            ...\array_map('strval', $inheritKeys),
        ]);

        return \array_intersect($changed, \array_values(self::POLICY_KEYS)) !== [];
    }
}

<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Service;

use Weline\Framework\Cache\Service\ScopeSharedMemo;
use Weline\Framework\Http\Security\SecurityHeaderPolicyOverrideProviderInterface;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Model\SystemConfig;

final class SystemConfigSecurityHeaderPolicyOverrideProvider implements SecurityHeaderPolicyOverrideProviderInterface
{
    private const PROCESS_BAG_MAX = 32;

    /** @var array<string, array{csp:string,csp_report_only:string,cors_origins:string}> */
    private static array $processOverridesByScope = [];

    public function __construct(
        private readonly SystemConfig $systemConfig,
        private readonly SystemConfigScopeResolver $scopeResolver,
    ) {
    }

    public static function clearProcessCache(): void
    {
        self::$processOverridesByScope = [];
        ScopeSharedMemo::purgeProcessPrefix('sec.headers|');
    }

    public function currentOverride(): array
    {
        $identity = RequestContext::scopeIdentity();
        if (!$identity instanceof ScopeIdentity) {
            $identity = ScopeIdentity::global();
        }
        $scope = $this->scopeResolver->toStorageScope($identity);
        if (isset(self::$processOverridesByScope[$scope])) {
            return self::$processOverridesByScope[$scope];
        }

        $override = ScopeSharedMemo::rememberScoped(
            'system_config',
            'sec.headers',
            fn (): array => [
                'csp' => $this->value(SecurityPolicyConfigGuard::KEY_CSP, $scope),
                'csp_report_only' => $this->value(SecurityPolicyConfigGuard::KEY_CSP_REPORT_ONLY, $scope),
                'cors_origins' => $this->value(SecurityPolicyConfigGuard::KEY_CORS_ORIGINS, $scope),
            ],
            $identity,
            600,
        );
        if (!\is_array($override)) {
            $override = [
                'csp' => '',
                'csp_report_only' => '',
                'cors_origins' => '',
            ];
        }

        if (!isset(self::$processOverridesByScope[$scope])
            && \count(self::$processOverridesByScope) >= self::PROCESS_BAG_MAX
        ) {
            $first = \array_key_first(self::$processOverridesByScope);
            if ($first !== null) {
                unset(self::$processOverridesByScope[$first]);
            }
        }
        self::$processOverridesByScope[$scope] = $override;

        return $override;
    }

    private function value(string $key, string $scope): string
    {
        return (string)$this->systemConfig->getConfig(
            key: $key,
            module: SecurityPolicyConfigGuard::MODULE,
            area: SecurityPolicyConfigGuard::AREA,
            default: '',
            scope: $scope,
            locale: SystemConfig::LOCALE_DEFAULT,
        );
    }
}

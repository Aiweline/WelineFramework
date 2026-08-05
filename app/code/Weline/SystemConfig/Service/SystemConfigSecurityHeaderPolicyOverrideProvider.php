<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Service;

use Weline\Framework\Http\Security\SecurityHeaderPolicyOverrideProviderInterface;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\SystemConfig\Model\SystemConfig;

final class SystemConfigSecurityHeaderPolicyOverrideProvider implements SecurityHeaderPolicyOverrideProviderInterface
{
    public function __construct(
        private readonly SystemConfig $systemConfig,
        private readonly SystemConfigScopeResolver $scopeResolver,
    ) {
    }

    public function currentOverride(): array
    {
        $identity = RequestContext::scopeIdentity();
        if (!$identity instanceof ScopeIdentity) {
            $identity = ScopeIdentity::global();
        }
        $scope = $this->scopeResolver->toStorageScope($identity);

        return [
            'csp' => $this->value(SecurityPolicyConfigGuard::KEY_CSP, $scope),
            'csp_report_only' => $this->value(SecurityPolicyConfigGuard::KEY_CSP_REPORT_ONLY, $scope),
            'cors_origins' => $this->value(SecurityPolicyConfigGuard::KEY_CORS_ORIGINS, $scope),
        ];
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

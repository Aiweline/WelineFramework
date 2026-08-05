<?php

declare(strict_types=1);

namespace Weline\Framework\Http\Security;

interface SecurityHeaderPolicyOverrideProviderInterface
{
    /**
     * Return the current request Scope override. Values are still intersected
     * with the immutable Env baseline by SecurityHeaderPolicyService.
     *
     * @return array{csp?:string,csp_report_only?:string,cors_origins?:string}
     */
    public function currentOverride(): array;
}

<?php

declare(strict_types=1);

namespace Weline\Payment\Api;

/**
 * Optional asset policy contribution (e.g. B2B b2b_credit).
 *
 * Register via module provides key prefix {@see CAPABILITY_PREFIX}.
 */
interface PaymentAssetPolicyProviderInterface
{
    public const CAPABILITY_PREFIX = 'payment.asset_policy.';

    /**
     * @param array<string, mixed> $context Scope / payable context from Payment
     * @return array<string, array<string, mixed>> asset_code => policy fragment
     */
    public function getAssetPolicies(array $context = []): array;
}

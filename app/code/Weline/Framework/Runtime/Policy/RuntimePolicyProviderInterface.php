<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Policy;

/**
 * Compile-time extension point for module-owned runtime policies.
 *
 * Providers are instantiated only by framework:compile. Request processing
 * consumes the compiled descriptors and never calls a module provider.
 */
interface RuntimePolicyProviderInterface
{
    public const CAPABILITY_PREFIX = 'runtime_policy_provider.';

    /**
     * @return list<RuntimePolicyDescriptor|array<string, mixed>>
     */
    public function policies(): array;
}

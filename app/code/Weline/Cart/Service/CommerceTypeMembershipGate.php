<?php

declare(strict_types=1);

namespace Weline\Cart\Service;

use Weline\Cart\Api\CommerceTypeMembershipCheckerInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Runtime\RuntimeProviderResolution;
use Weline\Framework\Runtime\RuntimeProviderResolver;

/**
 * Cart-owned optional membership gate. Absent provider means no membership
 * for non-toc types (fail closed for tob); toc always allowed.
 */
final class CommerceTypeMembershipGate
{
    public function __construct(
        private readonly ?CommerceTypeMembershipCheckerInterface $checker = null,
        private readonly ?RuntimeProviderResolver $providerResolver = null,
    ) {
    }

    public static function forTesting(?CommerceTypeMembershipCheckerInterface $checker = null): self
    {
        return new self($checker);
    }

    public function hasMembership(string $typeCode, int $customerId, int $websiteId): bool
    {
        $code = strtolower(trim($typeCode));
        if ($code === '' || $code === CommerceCartTypeRegistry::CODE_TOC) {
            return true;
        }

        $provider = $this->checker;
        if ($provider === null) {
            $provider = $this->resolveProvider();
        }
        if ($provider === null) {
            return false;
        }

        try {
            return $provider->hasMembership($code, $customerId, $websiteId);
        } catch (\Throwable) {
            return false;
        }
    }

    private function resolveProvider(): ?CommerceTypeMembershipCheckerInterface
    {
        try {
            $resolver = $this->providerResolver
                ?? ObjectManager::getInstance(RuntimeProviderResolver::class);
            $resolution = $resolver->resolveDetailed(CommerceTypeMembershipCheckerInterface::class);
        } catch (\Throwable) {
            return null;
        }

        if ($resolution->status === RuntimeProviderResolution::NOT_CONFIGURED) {
            return null;
        }
        if (!$resolution->isAvailable()
            || !$resolution->provider instanceof CommerceTypeMembershipCheckerInterface
        ) {
            return null;
        }

        return $resolution->provider;
    }
}

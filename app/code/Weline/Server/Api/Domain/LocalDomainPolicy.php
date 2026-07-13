<?php
declare(strict_types=1);

namespace Weline\Server\Api\Domain;

use Weline\Server\Service\LocalDomainPolicy as InternalLocalDomainPolicy;

/**
 * Public, allocation-free local-domain policy facade.
 *
 * Optional modules may depend on this API without reaching into Server's
 * service implementation. All decisions remain delegated to the single
 * Server-owned policy so WLS startup and website routing cannot drift.
 */
final class LocalDomainPolicy
{
    public const TEST_ROOT_DOMAIN = InternalLocalDomainPolicy::TEST_ROOT_DOMAIN;
    public const LEGACY_LOCAL_TEST_ROOT_DOMAIN = InternalLocalDomainPolicy::LEGACY_LOCAL_TEST_ROOT_DOMAIN;
    public const LOOPBACK_ROOT_DOMAIN = InternalLocalDomainPolicy::LOOPBACK_ROOT_DOMAIN;

    public const TEST_WILDCARD_DOMAIN = InternalLocalDomainPolicy::TEST_WILDCARD_DOMAIN;
    public const LEGACY_LOCAL_TEST_WILDCARD_DOMAIN = InternalLocalDomainPolicy::LEGACY_LOCAL_TEST_WILDCARD_DOMAIN;
    public const LOOPBACK_WILDCARD_DOMAIN = InternalLocalDomainPolicy::LOOPBACK_WILDCARD_DOMAIN;

    public static function isDevelopmentMode(?string $deployMode = null): bool
    {
        return InternalLocalDomainPolicy::isDevelopmentMode($deployMode);
    }

    public static function currentRootDomain(?string $deployMode = null): string
    {
        return InternalLocalDomainPolicy::currentRootDomain($deployMode);
    }

    public static function currentWildcardDomain(?string $deployMode = null): string
    {
        return InternalLocalDomainPolicy::currentWildcardDomain($deployMode);
    }

    public static function buildProjectHost(string $shortHash, ?string $deployMode = null): string
    {
        return InternalLocalDomainPolicy::buildProjectHost($shortHash, $deployMode);
    }

    public static function normalizeDomain(string $domain): string
    {
        return InternalLocalDomainPolicy::normalizeDomain($domain);
    }

    public static function resolveRootDomain(string $domain): ?string
    {
        return InternalLocalDomainPolicy::resolveRootDomain($domain);
    }

    public static function resolveWildcardDomain(string $domain): ?string
    {
        return InternalLocalDomainPolicy::resolveWildcardDomain($domain);
    }

    public static function isManagedLocalDomain(string $domain): bool
    {
        return InternalLocalDomainPolicy::isManagedLocalDomain($domain);
    }

    public static function isManagedWildcardDomain(string $domain): bool
    {
        return InternalLocalDomainPolicy::isManagedWildcardDomain($domain);
    }

    public static function isManagedSingleLabelSubdomain(string $domain): bool
    {
        return InternalLocalDomainPolicy::isManagedSingleLabelSubdomain($domain);
    }

    public static function isStandardProjectHost(string $domain): bool
    {
        return InternalLocalDomainPolicy::isStandardProjectHost($domain);
    }

    public static function requiresHostsEntry(string $domain): bool
    {
        return InternalLocalDomainPolicy::requiresHostsEntry($domain);
    }

    public static function resolvesViaLoopbackSuffix(string $domain): bool
    {
        return InternalLocalDomainPolicy::resolvesViaLoopbackSuffix($domain);
    }
}

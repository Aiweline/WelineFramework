<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Derives bounded host-internal lease names from public instance identities.
 *
 * Public instance IDs may consume the complete 128-byte protocol allowance,
 * while supplemental listener roles need a distinct allocator identity. Short
 * legacy names remain byte-for-byte compatible. Long names retain a readable
 * prefix and include a role-bound digest so truncation cannot alias projects,
 * instances, or listener roles.
 */
final class GatewayLeaseIdentity
{
    public const ROLE_INITIAL_BACKEND = 'gateway-initial-backend';
    public const ROLE_FALLBACK = 'gateway-fallback';
    public const ROLE_BACKEND = 'gateway-backend';

    private const MAX_IDENTITY_BYTES = 128;
    private const HASH_BYTES = 24;
    private const ROLES = [
        self::ROLE_INITIAL_BACKEND,
        self::ROLE_FALLBACK,
        self::ROLE_BACKEND,
    ];

    public static function forRole(string $instanceName, string $role): string
    {
        if (\preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', $instanceName) !== 1) {
            throw new \InvalidArgumentException(
                'Gateway lease instance identity is outside protocol bounds.',
            );
        }
        if (!\in_array($role, self::ROLES, true)) {
            throw new \InvalidArgumentException('Gateway lease role is unsupported.');
        }

        $legacy = $instanceName . '-' . $role;
        if (\strlen($legacy) <= self::MAX_IDENTITY_BYTES) {
            return $legacy;
        }

        $digest = \substr(\hash(
            'sha256',
            "wls-gateway-lease-identity/1\0" . $instanceName . "\0" . $role,
        ), 0, self::HASH_BYTES);
        $suffix = '-' . $role . '-' . $digest;
        $prefixBytes = self::MAX_IDENTITY_BYTES - \strlen($suffix);
        if ($prefixBytes < 1) {
            throw new \LogicException('Gateway lease role leaves no bounded instance prefix.');
        }
        $prefix = \rtrim(\substr($instanceName, 0, $prefixBytes), '._-');
        if ($prefix === '') {
            $prefix = $instanceName[0];
        }
        $identity = $prefix . $suffix;
        if (\strlen($identity) > self::MAX_IDENTITY_BYTES
            || \preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,127}\z/D', $identity) !== 1
        ) {
            throw new \LogicException('Derived gateway lease identity is invalid.');
        }
        return $identity;
    }

    private function __construct()
    {
    }
}

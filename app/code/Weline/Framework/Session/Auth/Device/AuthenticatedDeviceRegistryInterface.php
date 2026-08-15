<?php

declare(strict_types=1);

namespace Weline\Framework\Session\Auth\Device;

/** Optional storage-independent device lifecycle for authenticated sessions. */
interface AuthenticatedDeviceRegistryInterface
{
    public function supportsArea(string $area): bool;

    public function register(
        AuthenticatedDeviceContext $context,
        ?AuthenticatedLoginContext $loginContext = null,
    ): AuthenticatedDeviceValidation;

    public function validate(AuthenticatedDeviceContext $context): AuthenticatedDeviceValidation;

    public function revokeCurrent(AuthenticatedDeviceContext $context, string $reason = 'logout'): void;
}

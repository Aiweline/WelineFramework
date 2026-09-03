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

    /**
     * After a shared Session id rotation, move an already-bound device onto the
     * new session id. Callers must pass the device public id from the surviving
     * Session payload; this is not a cross-browser recovery path.
     */
    public function rebindToCurrentSession(AuthenticatedDeviceContext $context): AuthenticatedDeviceValidation;
}

<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Session;

use PHPUnit\Framework\TestCase;

final class BackendRestDeviceFailClosedContractTest extends TestCase
{
    public function testLegacySessionIdRecoveryIsRestrictedToAnAbsentDeviceRegistry(): void
    {
        $path = dirname(__DIR__, 3) . '/App/Controller/BackendRestController.php';
        $source = (string)file_get_contents($path);

        self::assertStringContainsString(
            'resolveDetailed(AuthenticatedDeviceRegistryInterface::class)',
            $source,
        );
        self::assertStringContainsString(
            'RuntimeProviderResolution::NOT_CONFIGURED',
            $source,
        );

        $failClosedGuard = strpos($source, 'if (!$this->legacySessionRecoveryAllowed())');
        $legacyLookup = strpos($source, '$this->resolveBackendUser($sessionId)');
        self::assertNotFalse($failClosedGuard);
        self::assertNotFalse($legacyLookup);
        self::assertLessThan($legacyLookup, $failClosedGuard);
    }
}

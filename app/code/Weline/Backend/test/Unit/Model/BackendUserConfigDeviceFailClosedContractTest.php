<?php

declare(strict_types=1);

namespace Weline\Backend\Test\Unit\Model;

use PHPUnit\Framework\TestCase;

final class BackendUserConfigDeviceFailClosedContractTest extends TestCase
{
    public function testSessIdFallbackIsRestrictedToAnAbsentDeviceRegistry(): void
    {
        $path = dirname(__DIR__, 3) . '/Model/BackendUserConfig.php';
        $source = (string)file_get_contents($path);

        self::assertStringContainsString(
            'resolveDetailed(AuthenticatedDeviceRegistryInterface::class)',
            $source,
        );
        self::assertStringContainsString(
            'RuntimeProviderResolution::NOT_CONFIGURED',
            $source,
        );

        $failClosedGuard = strpos($source, 'if (!$this->legacySessionLookupAllowed())');
        $legacyQuery = strpos($source, 'BackendUser::schema_fields_sess_id');
        self::assertNotFalse($failClosedGuard);
        self::assertNotFalse($legacyQuery);
        self::assertLessThan($legacyQuery, $failClosedGuard);
    }
}

<?php

declare(strict_types=1);

namespace Weline\Backend\Test\Unit;

use PHPUnit\Framework\TestCase;

final class BackendUserContextProviderWarmupIsolationContractTest extends TestCase
{
    public function testWarmupIdentityIsGuardedByTheCurrentRequestMarker(): void
    {
        $source = (string)\file_get_contents(
            BP . '/app/code/Weline/Backend/Service/BackendUserContextProvider.php'
        );

        $guard = \strpos($source, 'BackendWarmupContext::isInternalWarmupRequest($request)');
        $warmupRead = \strpos($source, 'BackendWarmupContext::currentUser()');
        $sessionRead = \strpos($source, 'createBackendSession()->getUser()');

        self::assertIsInt($guard);
        self::assertIsInt($warmupRead);
        self::assertIsInt($sessionRead);
        self::assertLessThan($warmupRead, $guard);
        self::assertLessThan($sessionRead, $warmupRead);
    }
}

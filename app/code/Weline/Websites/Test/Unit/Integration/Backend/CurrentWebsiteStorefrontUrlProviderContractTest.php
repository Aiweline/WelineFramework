<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Integration\Backend;

use PHPUnit\Framework\TestCase;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);

final class CurrentWebsiteStorefrontUrlProviderContractTest extends TestCase
{
    public function testProviderIsRegisteredForBackendRuntime(): void
    {
        $module = require BP . '/app/code/Weline/Websites/etc/module.php';
        $provides = $module['provides'] ?? [];

        self::assertArrayHasKey(
            \Weline\Backend\Api\Runtime\CurrentWebsiteStorefrontUrlProviderInterface::class,
            $provides,
        );
        self::assertSame(
            \Weline\Websites\Integration\Backend\CurrentWebsiteStorefrontUrlProvider::class,
            $provides[\Weline\Backend\Api\Runtime\CurrentWebsiteStorefrontUrlProviderInterface::class],
        );
    }
}

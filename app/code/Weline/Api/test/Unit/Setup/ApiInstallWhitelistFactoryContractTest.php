<?php

declare(strict_types=1);

namespace Weline\Api\Test\Unit\Setup;

use PHPUnit\Framework\TestCase;

/**
 * ObjectManager auto-invokes create() for *Factory classes; Install must not call it twice.
 */
final class ApiInstallWhitelistFactoryContractTest extends TestCase
{
    public function testInstallUsesInterfaceNotDoubleFactoryCreate(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Setup/Install.php',
        );
        self::assertStringContainsString('WhitelistServiceInterface::class', $src);
        self::assertStringNotContainsString('WhitelistServiceInterfaceFactory::class)->create()', $src);
        self::assertStringNotContainsString('WhitelistServiceInterfaceFactory::class)->create(', $src);
    }
}

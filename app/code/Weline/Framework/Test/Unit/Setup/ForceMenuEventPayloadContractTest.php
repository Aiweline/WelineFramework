<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Setup;

use PHPUnit\Framework\TestCase;

/**
 * W3：Setup Upgrade 不得 soft-pull Backend MenuXmlReader；force_menu 走事件载荷。
 */
final class ForceMenuEventPayloadContractTest extends TestCase
{
    public function testUpgradeDoesNotSoftPullMenuXmlReader(): void
    {
        $src = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Setup/Console/Setup/Upgrade.php'
        );
        self::assertStringNotContainsString('MenuXmlReader::', $src);
        self::assertStringContainsString('withForceMenuEventData', $src);
        self::assertStringContainsString("'force_menu'", $src);
        self::assertStringContainsString('force_menu_release', $src);
    }

    public function testFrameworkBackendRestAliasRemoved(): void
    {
        $alias = dirname(__DIR__, 3) . '/App/Controller/BackendRestController.php';
        self::assertFileDoesNotExist($alias);
        $query = (string)file_get_contents(
            dirname(__DIR__, 3) . '/Controller/Backend/Api/Query.php'
        );
        self::assertStringContainsString('Weline\\Backend\\Controller\\BackendRestController', $query);
        self::assertStringNotContainsString('Framework\\App\\Controller\\BackendRestController', $query);
    }
}

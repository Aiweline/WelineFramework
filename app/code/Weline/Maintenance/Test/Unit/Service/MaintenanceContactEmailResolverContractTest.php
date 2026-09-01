<?php

declare(strict_types=1);

namespace Weline\Maintenance\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Maintenance\Service\MaintenanceContactEmailResolver;

final class MaintenanceContactEmailResolverContractTest extends TestCase
{
    public function testConfigKeyAndModuleAreStable(): void
    {
        self::assertSame('Weline_Maintenance', MaintenanceContactEmailResolver::MODULE);
        self::assertSame('maintenance/developer_email', MaintenanceContactEmailResolver::CONFIG_KEY);
        self::assertSame('support@example.com', MaintenanceContactEmailResolver::DEFAULT_EMAIL);
    }

    public function testResolverSourceUsesDedicatedConfigFirst(): void
    {
        $root = \dirname(__DIR__, 7);
        $source = (string) \file_get_contents(
            $root . '/app/code/Weline/Maintenance/Service/MaintenanceContactEmailResolver.php',
        );

        self::assertStringContainsString("self::CONFIG_KEY", $source);
        self::assertStringContainsString('BackendConfigStore', $source);
        self::assertStringContainsString('contact_email', $source);
        self::assertStringContainsString('FILTER_VALIDATE_EMAIL', $source);
    }

    public function testStaticGeneratorUsesResolver(): void
    {
        $root = \dirname(__DIR__, 7);
        $source = (string) \file_get_contents(
            $root . '/app/code/Weline/Maintenance/Service/MaintenanceStaticGenerator.php',
        );

        self::assertStringContainsString('MaintenanceContactEmailResolver::class', $source);
        self::assertStringNotContainsString("Env::getInstance()->getConfig('contact_email'", $source);
    }
}

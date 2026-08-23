<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Nginx\ManagedNginxPaths;

final class ManagedNginxPathsDefaultsTest extends TestCase
{
    public function testAutoManagedDefaultsToEnabled(): void
    {
        $paths = new ManagedNginxPaths('/tmp/wls-nginx-defaults-test', []);
        self::assertSame('auto', $paths->managedMode());
        self::assertTrue($paths->managedEnabled());
        self::assertTrue($paths->autoStartEnabled());
    }

    public function testExplicitFalseDisablesManagedEdge(): void
    {
        $paths = new ManagedNginxPaths('/tmp/wls-nginx-defaults-test', [
            'managed' => false,
            'auto_start' => false,
        ]);
        self::assertFalse($paths->managedEnabled());
        self::assertFalse($paths->autoStartEnabled());
    }
}

<?php

declare(strict_types=1);

namespace Weline\Server\Test\Unit\Service\Edge\Nginx;

use PHPUnit\Framework\TestCase;
use Weline\Server\Service\Edge\Nginx\ManagedNginxOriginMode;
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

    public function testOriginModeDefaultsToWlsAndHonorsFpmListen(): void
    {
        $default = new ManagedNginxPaths('/tmp/wls-nginx-defaults-test', []);
        self::assertSame(ManagedNginxOriginMode::WLS, $default->originMode());
        self::assertFalse($default->isFpmOrigin());

        $fpm = new ManagedNginxPaths('/tmp/wls-nginx-defaults-test', [
            'origin_mode' => 'fpm',
            'fpm_listen' => '127.0.0.1:9001',
        ]);
        self::assertTrue($fpm->isFpmOrigin());
        self::assertSame('127.0.0.1:9001', $fpm->fpmListen());
        self::assertStringEndsWith(
            DIRECTORY_SEPARATOR . 'pub',
            $fpm->pubRoot(),
        );
        self::assertStringContainsString(
            DIRECTORY_SEPARATOR . 'acme' . DIRECTORY_SEPARATOR . 'challenge',
            $fpm->acmeChallengeRoot(),
        );
    }
}

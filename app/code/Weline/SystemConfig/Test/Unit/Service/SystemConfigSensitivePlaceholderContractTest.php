<?php

declare(strict_types=1);

namespace Weline\SystemConfig\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\SystemConfig\Service\SystemConfigCenterService;

/**
 * Sensitive placeholder / import_file save guards.
 */
final class SystemConfigSensitivePlaceholderContractTest extends TestCase
{
    public function testSensitiveUnchangedPlaceholderRecognizesMasks(): void
    {
        $service = (new \ReflectionClass(SystemConfigCenterService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(SystemConfigCenterService::class, 'isSensitiveUnchangedPlaceholder');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($service, ''));
        self::assertTrue($method->invoke($service, '***'));
        self::assertTrue($method->invoke($service, '********'));
        self::assertTrue($method->invoke($service, '*****'));
        self::assertFalse($method->invoke($service, 'GOCSPX-real-secret'));
    }

    public function testImportFileFieldDetected(): void
    {
        $service = (new \ReflectionClass(SystemConfigCenterService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(SystemConfigCenterService::class, 'isImportFileField');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($service, ['type' => 'import_file']));
        self::assertFalse($method->invoke($service, ['type' => 'textarea']));
    }
}

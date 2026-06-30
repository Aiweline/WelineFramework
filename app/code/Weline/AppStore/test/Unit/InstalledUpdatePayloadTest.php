<?php
declare(strict_types=1);

namespace Weline\AppStore\Test\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Weline\AppStore\Controller\Backend\Installed;

class InstalledUpdatePayloadTest extends TestCase
{
    public function testNormalizeUpdatePayloadDetectsAvailableUpdate(): void
    {
        $controller = (new ReflectionClass(Installed::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(Installed::class, 'normalizeUpdatePayload');
        $method->setAccessible(true);

        $result = $method->invoke($controller, [
            'success' => true,
            'data' => [
                'updates' => [
                    [
                        'module_name' => 'Weline_AppStore',
                        'latest_version' => '1.2.0',
                        'module_id' => 1001,
                        'license_key' => 'license-123',
                    ],
                ],
            ],
        ], [
            [
                'name' => 'Weline_AppStore',
                'version' => '1.1.0',
            ],
        ]);

        $this->assertArrayHasKey('Weline_AppStore', $result);
        $this->assertSame('1.2.0', $result['Weline_AppStore']['latest_version']);
        $this->assertTrue($result['Weline_AppStore']['update_available']);
        $this->assertSame(1001, $result['Weline_AppStore']['platform_module_id']);
        $this->assertSame('license-123', $result['Weline_AppStore']['license_key']);
    }

    public function testNormalizeUpdatePayloadKeepsExplicitNoUpdateState(): void
    {
        $controller = (new ReflectionClass(Installed::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(Installed::class, 'normalizeUpdatePayload');
        $method->setAccessible(true);

        $result = $method->invoke($controller, [
            'success' => true,
            'data' => [
                'updates' => [
                    'Weline_AppStore' => [
                        'latest_version' => '1.2.0',
                        'update_available' => false,
                    ],
                ],
            ],
        ], [
            [
                'name' => 'Weline_AppStore',
                'version' => '1.1.0',
            ],
        ]);

        $this->assertArrayHasKey('Weline_AppStore', $result);
        $this->assertSame('1.2.0', $result['Weline_AppStore']['latest_version']);
        $this->assertFalse($result['Weline_AppStore']['update_available']);
    }

    public function testNormalizeUpdatePayloadFallsBackToLocalInstallMetadata(): void
    {
        $controller = (new ReflectionClass(Installed::class))->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(Installed::class, 'normalizeUpdatePayload');
        $method->setAccessible(true);

        $result = $method->invoke($controller, [
            'success' => true,
            'data' => [
                'updates' => [
                    'Weline_AppStore' => [
                        'latest_version' => '1.2.0',
                    ],
                ],
            ],
        ], [
            [
                'name' => 'Weline_AppStore',
                'version' => '1.1.0',
                'platform_module_id' => 1001,
                'license_key' => 'local-license',
            ],
        ]);

        $this->assertArrayHasKey('Weline_AppStore', $result);
        $this->assertTrue($result['Weline_AppStore']['update_available']);
        $this->assertSame(1001, $result['Weline_AppStore']['platform_module_id']);
        $this->assertSame('local-license', $result['Weline_AppStore']['license_key']);
    }
}

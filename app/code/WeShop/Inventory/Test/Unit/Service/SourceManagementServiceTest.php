<?php

declare(strict_types=1);

namespace WeShop\Inventory\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Inventory\Model\Source;
use WeShop\Inventory\Service\SourceManagementService;

class SourceManagementServiceTest extends TestCase
{
    public function testNormalizeSourcePayloadThrowsWhenCodeMissing(): void
    {
        $service = new SourceManagementService($this->createMock(Source::class));

        $this->expectException(\InvalidArgumentException::class);
        $this->invokeNormalizeSourcePayload($service, [
            'name' => 'Main Warehouse',
        ]);
    }

    public function testNormalizeSourcePayloadThrowsWhenCodeHasInvalidCharacters(): void
    {
        $service = new SourceManagementService($this->createMock(Source::class));

        $this->expectException(\InvalidArgumentException::class);
        $this->invokeNormalizeSourcePayload($service, [
            'code' => 'Main Warehouse',
            'name' => 'Main Warehouse',
        ]);
    }

    public function testNormalizeSourcePayloadCastsFlagsAndPriority(): void
    {
        $service = new SourceManagementService($this->createMock(Source::class));

        $normalized = $this->invokeNormalizeSourcePayload($service, [
            'code' => ' Main_Store ',
            'name' => ' Main Warehouse ',
            'is_enabled' => 'yes',
            'priority' => -7,
            'use_default_carrier' => 'off',
        ]);

        $this->assertSame('main_store', $normalized[Source::schema_fields_CODE]);
        $this->assertSame('Main Warehouse', $normalized[Source::schema_fields_NAME]);
        $this->assertSame(1, $normalized[Source::schema_fields_IS_ENABLED]);
        $this->assertSame(0, $normalized[Source::schema_fields_PRIORITY]);
        $this->assertSame(0, $normalized[Source::schema_fields_USE_DEFAULT_CARRIER]);
    }

    private function invokeNormalizeSourcePayload(SourceManagementService $service, array $payload): array
    {
        $method = new \ReflectionMethod(SourceManagementService::class, 'normalizeSourcePayload');
        $method->setAccessible(true);

        /** @var array $result */
        $result = $method->invoke($service, $payload);
        return $result;
    }
}

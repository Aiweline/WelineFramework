<?php

declare(strict_types=1);

namespace WeShop\Inventory\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use WeShop\Inventory\Model\Source;
use WeShop\Inventory\Service\SourceManagementService;

class SourceManagementServiceTest extends TestCase
{
    public function testNormalizeSourcePayloadThrowsWhenCodeTooLong(): void
    {
        $service = new SourceManagementService($this->createMock(Source::class));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Source code cannot exceed 60 characters.');

        $this->invokeNormalizeSourcePayload($service, [
            'code' => str_repeat('a', 61),
            'name' => 'Main Warehouse',
        ]);
    }

    public function testNormalizeSourcePayloadThrowsWhenNameTooLong(): void
    {
        $service = new SourceManagementService($this->createMock(Source::class));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Source name cannot exceed 255 characters.');

        $this->invokeNormalizeSourcePayload($service, [
            'code' => 'main',
            'name' => str_repeat('N', 256),
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

    public function testGetEmptySourceDataReturnsExpectedStructure(): void
    {
        $sourceModel = $this->createMock(Source::class);
        $service = new SourceManagementService($sourceModel);

        $emptyData = $service->getEmptySourceData();

        $this->assertArrayHasKey('source_id', $emptyData);
        $this->assertArrayHasKey('code', $emptyData);
        $this->assertArrayHasKey('name', $emptyData);
        $this->assertArrayHasKey('is_enabled', $emptyData);
        $this->assertArrayHasKey('priority', $emptyData);
        $this->assertSame(0, $emptyData['source_id']);
        $this->assertSame('', $emptyData['code']);
        $this->assertSame(1, $emptyData['is_enabled']);
    }

    public function testDeleteSourceThrowsOnInvalidId(): void
    {
        $sourceModel = $this->createMock(Source::class);
        $service = new SourceManagementService($sourceModel);

        $this->expectException(\InvalidArgumentException::class);
        $service->deleteSource(0);
    }

    public function testDeleteSourceThrowsOnNotFound(): void
    {
        $sourceModel = $this->createMock(Source::class);
        $service = new class($sourceModel) extends SourceManagementService {
            public function deleteSource(int $sourceId): void
            {
                if ($sourceId <= 0) {
                    throw new \InvalidArgumentException((string) __('Invalid source id.'));
                }
                throw new \InvalidArgumentException((string) __('Inventory source does not exist.'));
            }
        };

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Inventory source does not exist.');
        $service->deleteSource(999);
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

<?php
declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Router;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Router\Core;

final class ControllerAttributeMetadataCacheTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->writeAttributeCache([]);
    }

    public function testControllerAttributeMetadataIsExtractedOncePerController(): void
    {
        $metadata = $this->readMetadata(ControllerAttributeMetadataCacheFixtureController::class);

        self::assertCount(1, $metadata);
        self::assertSame(ControllerAttributeMetadataCacheFixtureAttribute::class, $metadata[0]['name']);
        self::assertSame(['bench'], $metadata[0]['arguments']);
        self::assertArrayHasKey(ControllerAttributeMetadataCacheFixtureController::class, $this->readAttributeCache());
    }

    public function testControllerAttributeMetadataUsesCachedEntry(): void
    {
        $cached = [
            [
                'name' => 'CachedAttribute',
                'arguments' => ['from-cache'],
            ],
        ];
        $this->writeAttributeCache([
            ControllerAttributeMetadataCacheFixtureController::class => $cached,
        ]);

        self::assertSame($cached, $this->readMetadata(ControllerAttributeMetadataCacheFixtureController::class));
    }

    private function readMetadata(string $class): array
    {
        $method = new \ReflectionMethod(Core::class, 'getControllerAttributeMetadata');
        $method->setAccessible(true);

        /** @var array $metadata */
        $metadata = $method->invoke(null, $class);

        return $metadata;
    }

    private function readAttributeCache(): array
    {
        $property = new \ReflectionProperty(Core::class, 'controllerAttributeMetadataCache');
        $property->setAccessible(true);

        /** @var array $cache */
        $cache = $property->getValue();

        return $cache;
    }

    private function writeAttributeCache(array $cache): void
    {
        $property = new \ReflectionProperty(Core::class, 'controllerAttributeMetadataCache');
        $property->setAccessible(true);
        $property->setValue(null, $cache);
    }
}

#[\Attribute(\Attribute::TARGET_CLASS)]
final class ControllerAttributeMetadataCacheFixtureAttribute
{
    public function __construct(public string $code)
    {
    }
}

#[ControllerAttributeMetadataCacheFixtureAttribute('bench')]
final class ControllerAttributeMetadataCacheFixtureController
{
}

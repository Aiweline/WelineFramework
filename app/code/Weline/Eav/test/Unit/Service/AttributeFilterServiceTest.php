<?php

declare(strict_types=1);

namespace Weline\Eav\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Eav\Model\EavAttribute;
use Weline\Eav\Service\AttributeFilterService;
use Weline\Framework\Event\EventsManager;

class AttributeFilterServiceTest extends TestCase
{
    public function testBuildAttributeDataWithValuesSkipsInvalidAttributesWithoutIds(): void
    {
        $service = new AttributeFilterService($this->createMock(EventsManager::class));

        $attribute = $this->createMock(EavAttribute::class);
        $attribute->expects(self::once())->method('getId')->willReturn(0);
        $attribute->expects(self::never())->method('w_getValueModel');

        $result = $this->invokePrivate($service, 'buildAttributeDataWithValues', [[$attribute], [1, 2, 3]]);

        self::assertSame([], $result);
    }

    public function testBuildAttributeMetadataResultSkipsInvalidAttributesWithoutIds(): void
    {
        $service = new AttributeFilterService($this->createMock(EventsManager::class));

        $attribute = $this->createMock(EavAttribute::class);
        $attribute->expects(self::once())->method('getId')->willReturn(0);
        $attribute->expects(self::never())->method('getCode');

        $result = $this->invokePrivate($service, 'buildAttributeMetadataResult', [[$attribute]]);

        self::assertSame([], $result);
    }

    private function invokePrivate(object $instance, string $method, array $args): mixed
    {
        $reflection = new \ReflectionMethod($instance, $method);
        $reflection->setAccessible(true);

        return $reflection->invokeArgs($instance, $args);
    }
}

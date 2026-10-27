<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit\Observer;

use PHPUnit\Framework\TestCase;
use Weline\Theme\Observer\SetupUpgradeAfterPublishNotFoundStatic;

final class SetupUpgradePublishNotFoundStaticScopeTest extends TestCase
{
    public function testModuleScopedUpgradeIsDetected(): void
    {
        $ref = new \ReflectionClass(SetupUpgradeAfterPublishNotFoundStatic::class);
        $observer = $ref->newInstanceWithoutConstructor();
        $method = $ref->getMethod('isModuleScopedUpgrade');
        $method->setAccessible(true);

        self::assertTrue($method->invoke($observer, ['module' => 'Weline_Points']));
        self::assertTrue($method->invoke($observer, ['m' => ['Weline_Points']]));
        self::assertFalse($method->invoke($observer, []));
        self::assertFalse($method->invoke($observer, ['module' => '']));
    }
}

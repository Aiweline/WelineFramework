<?php

declare(strict_types=1);

namespace Weline\MediaManager\Test\Unit\Controller;

use PHPUnit\Framework\TestCase;
use Weline\MediaManager\Controller\Image;

/**
 * w/h 来自 GET，属系统边界输入：必须钳位，否则一次请求就能吃光 worker 内存。
 */
final class ImageControllerDimensionTest extends TestCase
{
    private static function normalize(mixed $raw): int
    {
        $method = new \ReflectionMethod(Image::class, 'normalizeDimension');
        $method->setAccessible(true);

        return (int)$method->invoke(null, $raw);
    }

    public function testMissingValueFallsBackToDefault(): void
    {
        self::assertSame(50, self::normalize(null));
        self::assertSame(50, self::normalize(''));
        self::assertSame(50, self::normalize('0'));
        self::assertSame(50, self::normalize('-10'));
        self::assertSame(50, self::normalize('abc'));
    }

    public function testPositiveValueIsKept(): void
    {
        self::assertSame(1, self::normalize('1'));
        self::assertSame(320, self::normalize('320'));
        self::assertSame(2048, self::normalize('2048'));
    }

    public function testValueAboveCapIsClamped(): void
    {
        self::assertSame(2048, self::normalize('2049'));
        self::assertSame(2048, self::normalize('999999'));
    }
}

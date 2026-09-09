<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Websites\Service\WebsiteSubPathValidator;

final class WebsiteSubPathValidatorTest extends TestCase
{
    private function validator(): WebsiteSubPathValidator
    {
        return new WebsiteSubPathValidator(
            ['en_US', 'zh_Hans_CN', 'en'],
            ['USD', 'CNY', 'EUR'],
        );
    }

    public function testEmptyAndShopAreValid(): void
    {
        $validator = $this->validator();
        self::assertTrue($validator->validate('')['valid']);
        self::assertTrue($validator->validate('/')['valid']);
        self::assertSame('/shop', $validator->assertValid('/shop'));
        self::assertSame('/shop/catalog', $validator->assertValid('shop/catalog'));
    }

    public function testCurrencyCodesRejectedCaseInsensitive(): void
    {
        $validator = $this->validator();
        foreach (['usd', 'USD', '/usd', '/shop/Usd', 'cny', '/EUR'] as $path) {
            $result = $validator->validate($path);
            self::assertFalse($result['valid'], $path);
            self::assertSame('currency', $result['matched_kind'], $path);
            self::assertStringContainsString('货币编码', $result['message'], $path);
        }
    }

    public function testLanguageCodesRejectedCaseInsensitive(): void
    {
        $validator = $this->validator();
        foreach (['en_US', '/en_us', 'zh_Hans_CN', '/EN', 'zh-Hans-CN'] as $path) {
            $result = $validator->validate($path);
            self::assertFalse($result['valid'], $path);
            self::assertSame('language', $result['matched_kind'], $path);
            self::assertStringContainsString('语言编码', $result['message'], $path);
        }
    }

    public function testLocaleShapedSegmentRejectedEvenWhenNotInstalled(): void
    {
        $validator = new WebsiteSubPathValidator([], []);
        $result = $validator->validate('/fr_FR');
        self::assertFalse($result['valid']);
        self::assertSame('language', $result['matched_kind']);
    }

    public function testReservedSegmentsRejected(): void
    {
        $validator = $this->validator();
        $result = $validator->validate('/admin');
        self::assertFalse($result['valid']);
        self::assertSame('reserved', $result['matched_kind']);
    }

    public function testAssertValidThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->validator()->assertValid('usd');
    }
}

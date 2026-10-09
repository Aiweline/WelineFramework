<?php

declare(strict_types=1);

namespace Weline\Websites\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Websites\Service\ScopeSwitcherPresenter;

final class ScopeSwitcherPresenterLabelTest extends TestCase
{
    public function testDisplayNamePrefersHumanNameOverCode(): void
    {
        self::assertSame('长安汉服', ScopeSwitcherPresenter::displayName('长安汉服', 'hanfu'));
        self::assertSame('hanfu', ScopeSwitcherPresenter::displayName('', 'hanfu'));
    }

    public function testCurrentLabelIsChannelNameOnly(): void
    {
        self::assertSame('默认渠道', ScopeSwitcherPresenter::formatCurrentLabel('默认渠道'));
        self::assertSame('B2B 批发', ScopeSwitcherPresenter::formatCurrentLabel('B2B 批发'));
        self::assertSame('', ScopeSwitcherPresenter::formatCurrentLabel('  '));
    }

    public function testStorefrontRouteFromEntryUrlExtractsPathForGetFrontendUrl(): void
    {
        $presenter = (new \ReflectionClass(ScopeSwitcherPresenter::class))
            ->newInstanceWithoutConstructor();
        $method = new ReflectionMethod(ScopeSwitcherPresenter::class, 'storefrontRouteFromEntryUrl');
        $method->setAccessible(true);

        self::assertSame(
            'b2b',
            $method->invoke($presenter, 'https://p05113ef3.test.weline.com/b2b'),
        );
        self::assertSame(
            'e2e-b2b-commerce/web',
            $method->invoke($presenter, 'https://p05113ef3.test.weline.com/e2e-b2b-commerce/web'),
        );
        self::assertSame('/', $method->invoke($presenter, 'https://p05113ef3.test.weline.com/'));
        self::assertSame('b2b', $method->invoke($presenter, '/b2b'));
    }
}

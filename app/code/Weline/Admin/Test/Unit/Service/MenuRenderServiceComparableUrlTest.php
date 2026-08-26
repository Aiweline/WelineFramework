<?php

declare(strict_types=1);

namespace Weline\Admin\Test\Unit\Service;

\defined('BP') || \define('BP', \dirname(__DIR__, 7) . \DIRECTORY_SEPARATOR);
require_once BP . 'app/bootstrap.php';

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Admin\Model\MenuAccessLog;
use Weline\Admin\Service\MenuRenderService;

final class MenuRenderServiceComparableUrlTest extends TestCase
{
    public function testNormalizeComparableUrlIgnoresExplicitLocalePrefix(): void
    {
        $service = new MenuRenderService($this->createStub(MenuAccessLog::class));
        $method = new ReflectionMethod(MenuRenderService::class, 'normalizeComparableUrl');
        $method->setAccessible(true);

        $withLocale = $method->invoke(
            $service,
            'https://p05113ef3.weline.test:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/zh_Hans_CN/eav/backend/manager'
        );
        $withoutLocale = $method->invoke(
            $service,
            'https://p05113ef3.weline.test:9555/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/eav/backend/manager'
        );

        self::assertSame('eav/backend/manager', $withLocale);
        self::assertSame('eav/backend/manager', $withoutLocale);
    }

    public function testNormalizeComparableUrlIgnoresCurrencyAndLocalePrefix(): void
    {
        $service = new MenuRenderService($this->createStub(MenuAccessLog::class));
        $method = new ReflectionMethod(MenuRenderService::class, 'normalizeComparableUrl');
        $method->setAccessible(true);

        $withCurrencyLocale = $method->invoke(
            $service,
            '/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/CNY/zh_Hans_CN/product/backend/catalog/index'
        );
        $withoutLocalization = $method->invoke(
            $service,
            '/jRaxfEJaRUyO6ZBOA3wJX8bituje6oqH/product/backend/catalog/index'
        );

        self::assertSame('product/backend/catalog/index', $withCurrencyLocale);
        self::assertSame('product/backend/catalog/index', $withoutLocalization);
    }
}

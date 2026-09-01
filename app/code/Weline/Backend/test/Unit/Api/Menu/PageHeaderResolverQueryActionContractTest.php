<?php

declare(strict_types=1);

namespace Weline\Backend\Test\Unit\Api\Menu;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Backend\Api\Menu\PageHeaderResolver;
use Weline\Framework\Http\Request;

/**
 * 菜单 action 可带 query（如 target_scope）；页头匹配必须按路径解析。
 */
final class PageHeaderResolverQueryActionContractTest extends TestCase
{
    public function testActionPathStripsQueryString(): void
    {
        $resolver = $this->newResolverWithoutConstructor();
        $method = new ReflectionMethod(PageHeaderResolver::class, 'actionPath');
        $method->setAccessible(true);

        self::assertSame(
            'payment/backend/method',
            $method->invoke($resolver, 'payment/backend/method?target_scope=default.default.default'),
        );
        self::assertSame(
            'payment/backend/dashboard/index',
            $method->invoke($resolver, '/payment/backend/dashboard/index?target_scope=global'),
        );
        self::assertSame('payment/backend/transaction', $method->invoke($resolver, 'payment/backend/transaction'));
    }

    public function testExactActionMatchIgnoresMenuQuery(): void
    {
        $resolver = $this->newResolverWithoutConstructor();
        $method = new ReflectionMethod(PageHeaderResolver::class, 'isExactActionMatch');
        $method->setAccessible(true);

        $request = $this->createMock(Request::class);
        $request->method('getRouteUrlPath')->willReturn('payment/backend/method');

        self::assertTrue($method->invoke(
            $resolver,
            $request,
            'payment/backend/method?target_scope=default.default.default',
        ));
        self::assertFalse($method->invoke(
            $resolver,
            $request,
            'payment/backend/dashboard/index?target_scope=default.default.default',
        ));
    }

    public function testResolverIndexesPathOnlyAndPaymentMenuKeepsQueryAction(): void
    {
        $resolverSource = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Api/Menu/PageHeaderResolver.php',
        );
        self::assertStringContainsString('actionPath(', $resolverSource);
        self::assertStringContainsString("\$candidate . '?%'", $resolverSource);
        self::assertStringContainsString('pathOnly', $resolverSource);

        $menuXml = (string)file_get_contents(
            dirname(__DIR__, 5) . '/Payment/etc/backend/menu.xml',
        );
        self::assertStringContainsString(
            'payment/backend/method?target_scope=default.default.default',
            $menuXml,
        );
    }

    private function newResolverWithoutConstructor(): PageHeaderResolver
    {
        $ref = new \ReflectionClass(PageHeaderResolver::class);

        return $ref->newInstanceWithoutConstructor();
    }
}

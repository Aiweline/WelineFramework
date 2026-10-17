<?php

declare(strict_types=1);

namespace Weline\Backend\Test\Unit\Api\Menu;

use PHPUnit\Framework\TestCase;
use ReflectionMethod as ReflectionMethod;
use Weline\Backend\Api\Menu\PageHeaderResolver;
use Weline\Framework\Http\Request;

/**
 * Aggregated contract suite (merged from 1 *ContractTest files).
 * Observe one overall pass/fail via testAllBackendMenuContractsPass().
 */
final class BackendMenuContractsTest extends TestCase
{
    public function testAllBackendMenuContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'PageHeaderResolverQueryAction::testActionPathStripsQueryString' => function () {
            $this->assert_PageHeaderResolverQueryAction_testActionPathStripsQueryString();
        },
            'PageHeaderResolverQueryAction::testExactActionMatchIgnoresMenuQuery' => function () {
            $this->assert_PageHeaderResolverQueryAction_testExactActionMatchIgnoresMenuQuery();
        },
            'PageHeaderResolverQueryAction::testResolverIndexesPathOnlyAndPaymentMenuKeepsQueryAction' => function () {
            $this->assert_PageHeaderResolverQueryAction_testResolverIndexesPathOnlyAndPaymentMenuKeepsQueryAction();
        },
        ];
    }

private function assert_PageHeaderResolverQueryAction_testActionPathStripsQueryString(): void
{
    $resolver = $this->newResolverWithoutConstructor_PageHeaderResolverQueryAction();
    $method = new ReflectionMethod(PageHeaderResolver::class, 'actionPath');
    $method->setAccessible(true);
    self::assertSame('payment/backend/method', $method->invoke($resolver, 'payment/backend/method?target_scope=default.default.default'));
    self::assertSame('payment/backend/dashboard/index', $method->invoke($resolver, '/payment/backend/dashboard/index?target_scope=global'));
    self::assertSame('payment/backend/transaction', $method->invoke($resolver, 'payment/backend/transaction'));
}

private function assert_PageHeaderResolverQueryAction_testExactActionMatchIgnoresMenuQuery(): void
{
    $resolver = $this->newResolverWithoutConstructor_PageHeaderResolverQueryAction();
    $method = new ReflectionMethod(PageHeaderResolver::class, 'isExactActionMatch');
    $method->setAccessible(true);
    $request = $this->createMock(Request::class);
    $request->method('getRouteUrlPath')->willReturn('payment/backend/method');
    self::assertTrue($method->invoke($resolver, $request, 'payment/backend/method?target_scope=default.default.default'));
    self::assertFalse($method->invoke($resolver, $request, 'payment/backend/dashboard/index?target_scope=default.default.default'));
}

private function assert_PageHeaderResolverQueryAction_testResolverIndexesPathOnlyAndPaymentMenuKeepsQueryAction(): void
{
    $resolverSource = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Backend' . '/Api/Menu/PageHeaderResolver.php');
    self::assertStringContainsString('actionPath(', $resolverSource);
    self::assertStringContainsString("\$candidate . '?%'", $resolverSource);
    self::assertStringContainsString('pathOnly', $resolverSource);
    $menuXml = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline' . '/Payment/etc/backend/menu.xml');
    self::assertStringContainsString('payment/backend/method?target_scope=default.default.default', $menuXml);
}

private function newResolverWithoutConstructor_PageHeaderResolverQueryAction(): PageHeaderResolver
{
    $ref = new \ReflectionClass(PageHeaderResolver::class);
    return $ref->newInstanceWithoutConstructor();
}
}

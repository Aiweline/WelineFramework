<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\PublicApiAuthRouteMatcher;
use Weline\Framework\Http\Request;

class PublicApiAuthRouteMatcherTest extends TestCase
{
    public function testMatchesWeShopAuthTokenRouteWithApiPrefix(): void
    {
        $matcher = new PublicApiAuthRouteMatcher();

        $this->assertTrue($matcher->matches($this->createRequestMock(
            'api/weshop/rest/v1/auth/token',
            'Auth',
            'postToken',
            'WeShop\\Auth\\Api\\Rest\\V1\\Auth'
        )));
    }

    public function testMatchesWeShopContractAuthTokenRoute(): void
    {
        $matcher = new PublicApiAuthRouteMatcher();

        $this->assertTrue($matcher->matches($this->createRequestMock(
            'api/rest/v1/weshop/auth/token',
            'Auth',
            'postToken',
            'WeShop\\ApiBridge\\Api\\Rest\\V1\\Weshop\\Auth'
        )));
    }

    public function testMatchesWeShopContractChallengeVerifyRoute(): void
    {
        $matcher = new PublicApiAuthRouteMatcher();

        $this->assertTrue($matcher->matches($this->createRequestMock(
            'api/rest/v1/weshop/auth/challenge/verify',
            'Challenge',
            'postVerify',
            'WeShop\\ApiBridge\\Api\\Rest\\V1\\Weshop\\Auth\\Challenge'
        )));
    }

    public function testMatchesChallengeVerifyActionByControllerClass(): void
    {
        $matcher = new PublicApiAuthRouteMatcher();

        $this->assertTrue($matcher->matches($this->createRequestMock(
            'non-whitelisted/path',
            'Challenge',
            'postVerify',
            'WeShop\\Auth\\Api\\Rest\\V1\\Auth\\Challenge'
        )));
    }

    public function testMatchesFrontendDemoTableInitRouteWithPublicPrefix(): void
    {
        $matcher = new PublicApiAuthRouteMatcher();

        $this->assertTrue($matcher->matches($this->createRequestMock(
            'api123/CNY/zh_Hans_CN/datatable/rest/v1/demo-table/init-data',
            'DemoTable',
            'postInitData',
            'Weline\\DataTable\\Api\\Rest\\V1\\DemoTable'
        )));
    }

    public function testMatchesFrontendDemoFormFieldsRoute(): void
    {
        $matcher = new PublicApiAuthRouteMatcher();

        $this->assertTrue($matcher->matches($this->createRequestMock(
            'datatable/rest/v1/demo-form/fields',
            'DemoForm',
            'postFields',
            'Weline\\DataTable\\Api\\Rest\\V1\\DemoForm'
        )));
    }

    public function testDoesNotMatchProtectedFrontendApiRoute(): void
    {
        $matcher = new PublicApiAuthRouteMatcher();

        $this->assertFalse($matcher->matches($this->createRequestMock(
            'api/weshop/rest/v1/order/list',
            'Order',
            'getList',
            'WeShop\\Order\\Api\\Rest\\V1\\Order'
        )));
    }

    public function testDoesNotMatchProtectedDataTableFrontendApiRoute(): void
    {
        $matcher = new PublicApiAuthRouteMatcher();

        $this->assertFalse($matcher->matches($this->createRequestMock(
            'datatable/rest/v1/data-table/data',
            'DataTable',
            'postData',
            'Weline\\DataTable\\Api\\Rest\\V1\\DataTable'
        )));
    }

    public function testDoesNotMatchProtectedWeShopContractFrontendApiRoute(): void
    {
        $matcher = new PublicApiAuthRouteMatcher();

        $this->assertFalse($matcher->matches($this->createRequestMock(
            'api/rest/v1/weshop/order/list',
            'Order',
            'getList',
            'WeShop\\Order\\Api\\Rest\\V1\\Order'
        )));
    }

    private function createRequestMock(string $routeUrlPath, string $controller, string $action, string $controllerClass): Request
    {
        return new class($routeUrlPath, $controller, $action, $controllerClass) extends Request {
            public function __construct(
                private readonly string $routeUrlPathValue,
                private readonly string $controllerValue,
                private readonly string $actionValue,
                private readonly string $controllerClassValue
            ) {
            }

            public function getRouteUrlPath(string $url = ''): string
            {
                return $this->routeUrlPathValue;
            }

            public function getPath(): string
            {
                return $this->routeUrlPathValue;
            }

            public function getController(): string
            {
                return $this->controllerValue;
            }

            public function getAction(): string
            {
                return $this->actionValue;
            }

            public function getRouterData(string $key): mixed
            {
                return match ($key) {
                    'module_path' => '',
                    'controller' => $this->controllerClassValue,
                    default => null,
                };
            }
        };
    }
}

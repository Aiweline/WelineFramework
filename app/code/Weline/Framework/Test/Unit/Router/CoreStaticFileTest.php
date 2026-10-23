<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Router;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Weline\Framework\Http\NoRouterException;
use Weline\Framework\Http\Request;
use Weline\Framework\Http\Response;
use Weline\Framework\Http\ResponseTerminateException;
use Weline\Framework\Router\Core;

final class CoreStaticFileTest extends TestCase
{
    public function testModuleStaticUrlIsServedFromAppCode(): void
    {
        $core = $this->newCore();
        $url = '/Weline/Frontend/view/statics/js/weline.js';

        try {
            $core->StaticFile($url);
            self::fail('StaticFile must terminate with a file response.');
        } catch (NoRouterException $e) {
            self::fail('Module static must not 404: ' . $e->getMessage());
        } catch (ResponseTerminateException $e) {
            $response = $e->getResponse();
            self::assertSame(200, $response->getStatusCode());
            self::assertSame(
                (string)\file_get_contents(APP_CODE_PATH . 'Weline/Frontend/view/statics/js/weline.js'),
                $response->getBody()
            );
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function traversalUrls(): iterable
    {
        yield 'parent into project root json' => ['/Weline/Frontend/view/../../../../composer.json'];
        yield 'parent with backslashes' => ['/Weline/Frontend/view\\..\\..\\..\\..\\composer.json'];
        yield 'parent after query-free leading segment' => ['Weline/Theme/view/statics/../../../../../composer.json'];
    }

    /**
     * @dataProvider traversalUrls
     */
    public function testParentSegmentsAreRejected(string $url): void
    {
        $core = $this->newCore();

        $this->expectException(NoRouterException::class);
        $core->StaticFile($url);
    }

    public function testProcessUrlStaticBranchDoesNotUseParserUrlFlagAsPath(): void
    {
        $source = (string)\file_get_contents(APP_CODE_PATH . 'Weline/Framework/Router/Core.php');

        self::assertStringNotContainsString("w_env('parser_url', '')", $source);
        self::assertMatchesRegularExpression(
            '/if \(\$isStaticFile\) \{[\s\S]{0,600}?\$url = \$this->getStrippedUrlPath\(\);[\s\S]{0,600}?\$this->StaticFile\(\$url\);/',
            $source
        );
    }

    private function newCore(): Core
    {
        $response = $this->createMock(Response::class);
        $response->method('noRouter')->willThrowException(new NoRouterException(404, 'not found'));
        $request = $this->createMock(Request::class);
        $request->method('getResponse')->willReturn($response);

        $reflection = new ReflectionClass(Core::class);
        $core = $reflection->newInstanceWithoutConstructor();
        $property = $reflection->getProperty('request');
        $property->setValue($core, $request);

        return $core;
    }
}

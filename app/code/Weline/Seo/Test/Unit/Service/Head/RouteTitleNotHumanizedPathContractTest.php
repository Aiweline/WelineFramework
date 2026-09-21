<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service\Head;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Seo\Service\Head\PageSeoContextResolver;

final class RouteTitleNotHumanizedPathContractTest extends TestCase
{
    public function testRouteTitleNeverReturnsHumanizedUrlSegments(): void
    {
        $resolver = new PageSeoContextResolver();
        $method = new ReflectionMethod(PageSeoContextResolver::class, 'routeTitle');
        $method->setAccessible(true);

        $template = new class {
            /** @var array<string, mixed> */
            private array $data = [];

            public function getData(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }
        };

        self::assertSame('', $method->invoke($resolver, $template));

        $source = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Service/Head/PageSeoContextResolver.php'
        );
        self::assertStringContainsString('title×locale', $source);
        self::assertStringContainsString("return '';", $source);
        // Must not feed humanizeRouteSegment into the public <title> chain.
        $start = strpos($source, 'private function routeTitle(');
        $end = strpos($source, 'private function requestPath(', $start === false ? 0 : $start);
        self::assertNotFalse($start);
        self::assertNotFalse($end);
        $methodSrc = substr($source, (int)$start, (int)$end - (int)$start);
        self::assertStringNotContainsString('humanizeRouteSegment', $methodSrc);
    }
}

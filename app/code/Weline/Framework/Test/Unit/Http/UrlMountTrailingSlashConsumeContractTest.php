<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Weline\Framework\Http\Url;

/**
 * Exact mount + directory slash must consume like a bare mount (empty remainder).
 * Leaving "/" used to peel REQUEST_URI and rebind /~site/{code}/ to default.
 */
final class UrlMountTrailingSlashConsumeContractTest extends TestCase
{
    public function testExactMountTrailingSlashNormalizesToEmptyRemainder(): void
    {
        $method = new ReflectionMethod(Url::class, 'tryConsumeWebsiteBaseUrl');
        $method->setAccessible(true);

        $base = 'https://p05113ef3.test.weline.com/~site/grocery';
        $slash = $method->invoke(null, $base . '/', $base);
        self::assertTrue($slash['matched']);
        self::assertSame('', $slash['remainder']);

        $bare = $method->invoke(null, $base, $base);
        self::assertTrue($bare['matched']);
        self::assertSame('', $bare['remainder']);

        $deeper = $method->invoke(null, $base . '/products', $base);
        self::assertTrue($deeper['matched']);
        self::assertSame('/products', $deeper['remainder']);
    }
}

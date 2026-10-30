<?php

declare(strict_types=1);

namespace Weline\Framework\Test\Unit\Http;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Http\Fpc\StorefrontNotFoundFpcStore;

/**
 * Per-language special FPC store for storefront 404 pages.
 */
final class StorefrontNotFoundFpcStoreTest extends TestCase
{
    public function testFilePathSanitizesCodeAndNormalizesLang(): void
    {
        $path = StorefrontNotFoundFpcStore::filePath('shop', 'en-us');
        self::assertStringEndsWith(\DIRECTORY_SEPARATOR . 'shop' . \DIRECTORY_SEPARATOR . 'en_US.html', $path);
        self::assertStringContainsString('error-pages', $path);
        self::assertStringContainsString('not-found', $path);
    }

    public function testFilePathFallsBackToDefaultCodeOnInvalid(): void
    {
        $path = StorefrontNotFoundFpcStore::filePath('../etc', 'en_US');
        self::assertStringContainsString(\DIRECTORY_SEPARATOR . 'default' . \DIRECTORY_SEPARATOR, $path);
        self::assertStringNotContainsString('..', $path);
    }

    public function testReadReturnsNullWhenNothingCached(): void
    {
        self::assertNull(StorefrontNotFoundFpcStore::read('__no_such_site_' . \bin2hex(\random_bytes(4)), 'en_US'));
    }

    public function testPublishRejectsEmptyHtml(): void
    {
        self::assertFalse(StorefrontNotFoundFpcStore::publish('default', 'en_US', '   '));
    }
}

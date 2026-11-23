<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service\Health;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Service\Health\UrlHealthKey;

final class UrlHealthKeyTest extends TestCase
{
    public function testNormalizeLowercasesSchemeAndHostAndDropsDefaultPort(): void
    {
        self::assertSame(
            'https://example.com/path',
            UrlHealthKey::normalize('HTTPS://Example.COM:443/path#fragment')
        );
        self::assertSame(
            'http://example.com/path',
            UrlHealthKey::normalize('http://example.com:80/path')
        );
    }

    public function testProbeQueryParamIsIgnored(): void
    {
        self::assertSame(
            UrlHealthKey::hash('https://example.com/a?x=1'),
            UrlHealthKey::hash('https://example.com/a?x=1&w_seo_probe=42')
        );
    }

    public function testOtherQueryParamsAreSorted(): void
    {
        self::assertSame(
            UrlHealthKey::normalize('https://example.com/a?b=2&a=1'),
            UrlHealthKey::normalize('https://example.com/a?a=1&b=2')
        );
    }

    public function testTrailingSlashIsPreservedBecauseItCanBeADifferentPage(): void
    {
        self::assertNotSame(
            UrlHealthKey::normalize('https://example.com/a'),
            UrlHealthKey::normalize('https://example.com/a/')
        );
    }

    public function testOriginAndSameOrigin(): void
    {
        self::assertSame('https://example.com', UrlHealthKey::origin('https://example.com/a/b'));
        self::assertTrue(UrlHealthKey::sameOrigin('https://example.com/a', 'https://example.com/b'));
        self::assertFalse(UrlHealthKey::sameOrigin('https://example.com/a', 'https://other.com/b'));
    }

    public function testWithProbeParamKeepsExistingQuery(): void
    {
        self::assertSame(
            'https://example.com/a?x=1&w_seo_probe=run-1',
            UrlHealthKey::withProbeParam('https://example.com/a?x=1', 'run-1')
        );
    }
}

<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Test\Unit\Helper;

use GuoLaiRen\PageBuilder\Helper\GlrDownloadRegistry;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class GlrDownloadRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        $this->resetRegistryStatic();
        parent::tearDown();
    }

    private function resetRegistryStatic(): void
    {
        $rc = new ReflectionClass(GlrDownloadRegistry::class);
        $entries = $rc->getProperty('entries');
        $entries->setAccessible(true);
        $entries->setValue(null, []);
    }

    public function testCodeFromHrefIsDeterministicSha256(): void
    {
        $url = 'https://example.com/app.apk';
        $expected = 'glr_' . hash('sha256', $url);
        $this->assertSame($expected, GlrDownloadRegistry::codeFromHref($url));
        $this->assertSame($expected, GlrDownloadRegistry::codeFromHref($url));
    }

    public function testRegisterSameUrlReturnsSameCode(): void
    {
        $url = 'https://cdn.example/x.apk';
        $a = GlrDownloadRegistry::register($url, 'primary');
        $b = GlrDownloadRegistry::register($url, 'secondary');
        $this->assertSame($a, $b);
        $this->assertSame($url, GlrDownloadRegistry::all()[$a]['href']);
        $this->assertSame('primary', GlrDownloadRegistry::all()[$a]['slot']);
    }

    public function testRegisterDifferentUrlsDifferentCodes(): void
    {
        $u1 = 'https://a.example/1.apk';
        $u2 = 'https://b.example/2.apk';
        $c1 = GlrDownloadRegistry::register($u1, 'url');
        $c2 = GlrDownloadRegistry::register($u2, 'url');
        $this->assertNotSame($c1, $c2);
        $this->assertStringStartsWith('glr_', $c1);
        $this->assertMatchesRegularExpression('/^glr_[a-f0-9]{64}$/', $c1);
    }

    public function testCodeHref(): void
    {
        $code = 'glr_' . str_repeat('a', 64);
        $this->assertSame('#' . $code, GlrDownloadRegistry::codeHref($code));
    }
}

<?php

declare(strict_types=1);

namespace Weline\Dropship\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Dropship\Service\DropshipCategoryEnsureService;

final class DropshipCategoryEnsureServiceTest extends TestCase
{
    public function testSlugifyUsesPinyinForChinese(): void
    {
        $svc = new DropshipCategoryEnsureService();
        $slug = $svc->slugify('运动户外');
        self::assertStringContainsString('yun', $slug);
        self::assertStringContainsString('dong', $slug);
        self::assertDoesNotMatchRegularExpression('/^c[a-f0-9]{10,}$/', $slug);
    }

    public function testSlugifyKeepsEnglish(): void
    {
        $svc = new DropshipCategoryEnsureService();
        self::assertSame('sportswear', $svc->slugify('Sportswear'));
        self::assertSame('home-garden-furniture', $svc->slugify('Home, Garden & Furniture'));
    }

    public function testParseSegmentsKeepsReadableNames(): void
    {
        $svc = new DropshipCategoryEnsureService();
        $segments = $svc->parseSegments('运动户外 / Sportswear / Sports Accessories');
        self::assertCount(3, $segments);
        self::assertSame('运动户外', $segments[0]['name']);
        self::assertStringContainsString('yun', $segments[0]['code']);
        self::assertSame('Sportswear', $segments[1]['name']);
        self::assertSame('sportswear', $segments[1]['code']);
    }

    public function testParseSegmentsStripsShellSourcingProviderPrefix(): void
    {
        $svc = new DropshipCategoryEnsureService();
        $segments = $svc->parseSegments(
            '/sourcing/cj/Home, Garden & Furniture/Home Storage',
            'cj',
        );
        self::assertCount(2, $segments);
        self::assertSame('home-garden-furniture', $segments[0]['code']);
        self::assertSame('home-storage', $segments[1]['code']);
        foreach ($segments as $segment) {
            self::assertStringNotContainsString('/', $segment['code']);
            self::assertStringNotContainsString('sourcing', $segment['code']);
        }
    }

    public function testParseSegmentsKeepsLeafOnlyWhenRemoteIsAlreadyAccumulated(): void
    {
        $svc = new DropshipCategoryEnsureService();
        $segments = $svc->parseSegments(
            'sourcing / cj / Home Storage / Home Office Storage',
            'cj',
        );
        self::assertSame(
            ['home-storage', 'home-office-storage'],
            array_column($segments, 'code'),
        );
    }
}

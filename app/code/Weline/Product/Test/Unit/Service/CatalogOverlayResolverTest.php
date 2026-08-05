<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\CatalogOverlayResolver;

/**
 * TEST-P2A-04 / TEST-P2A-05 / TEST-P2A-06（解析层）：
 * same-Website Store fallback、cleared 终止、删除覆盖后恢复。
 */
final class CatalogOverlayResolverTest extends TestCase
{
    private CatalogOverlayResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new CatalogOverlayResolver();
    }

    public function testStoreWithoutOverlayFollowsWebsite(): void
    {
        $rows = [
            ['store_id' => 0, 'locale' => '', 'cleared' => false, 'value' => 'website-title'],
        ];
        $a = $this->resolver->resolveAttribute($rows, 11, 'zh_Hans_CN');
        self::assertTrue($a->isExplicit());
        self::assertSame('website-title', $a->value);
        self::assertSame(0, $a->resolvedStoreId);
    }

    public function testStoreOverlayDoesNotFollowWebsiteChange(): void
    {
        $rows = [
            ['store_id' => 0, 'locale' => '', 'cleared' => false, 'value' => 'website-v2'],
            ['store_id' => 22, 'locale' => '', 'cleared' => false, 'value' => 'store-b-override'],
        ];
        $storeA = $this->resolver->resolveAttribute($rows, 11, '');
        $storeB = $this->resolver->resolveAttribute($rows, 22, '');
        self::assertSame('website-v2', $storeA->value);
        self::assertSame(0, $storeA->resolvedStoreId);
        self::assertSame('store-b-override', $storeB->value);
        self::assertSame(22, $storeB->resolvedStoreId);
    }

    public function testClearedTerminatesLocaleAndParentFallback(): void
    {
        $rows = [
            ['store_id' => 0, 'locale' => '', 'cleared' => false, 'value' => 'website-default'],
            ['store_id' => 5, 'locale' => 'zh_Hans_CN', 'cleared' => true, 'value' => null, 'is_required' => true],
            ['store_id' => 5, 'locale' => 'en_US', 'cleared' => false, 'value' => 'should-not-reach'],
        ];
        $resolved = $this->resolver->resolveAttribute($rows, 5, 'zh_Hans_CN', ['en_US', '']);
        self::assertTrue($resolved->isCleared());
        self::assertNull($resolved->value);
        self::assertSame('cleared_at_scope', $resolved->diagnostic);
        self::assertSame(5, $resolved->resolvedStoreId);
        self::assertSame('zh_Hans_CN', $resolved->resolvedLocale);
    }

    public function testDeleteOverlayRestoresWebsiteInherit(): void
    {
        // After deleteOverlay, only website row remains
        $rows = [
            ['store_id' => 0, 'locale' => '', 'cleared' => false, 'value' => 'parent-again'],
        ];
        $resolved = $this->resolver->resolveAttribute($rows, 9, '');
        self::assertTrue($resolved->isExplicit());
        self::assertSame('parent-again', $resolved->value);
        self::assertSame(0, $resolved->resolvedStoreId);
    }

    public function testPriceClearedMakesUnresolvedParentInvisible(): void
    {
        $rows = [
            ['store_id' => 0, 'cleared' => false, 'value' => 1990],
            ['store_id' => 3, 'cleared' => true, 'value' => 0],
        ];
        $resolved = $this->resolver->resolvePrice($rows, 3);
        self::assertTrue($resolved->isCleared());
        self::assertSame('price_cleared_at_scope', $resolved->diagnostic);
    }

    public function testPriceDeleteOverlayRestoresParent(): void
    {
        $rows = [
            ['store_id' => 0, 'cleared' => false, 'value' => 2500],
        ];
        $resolved = $this->resolver->resolvePrice($rows, 3);
        self::assertTrue($resolved->isExplicit());
        self::assertSame(2500, $resolved->value);
    }

    public function testRequiredClearedDiagnostic(): void
    {
        $rows = [
            ['store_id' => 0, 'locale' => '', 'cleared' => false, 'value' => 'x', 'is_required' => true],
            ['store_id' => 1, 'locale' => '', 'cleared' => true, 'value' => null, 'is_required' => true],
        ];
        $diags = $this->resolver->publishDiagnostics($rows, 1, '');
        self::assertSame(['cleared_at_scope'], $diags);
    }

    public function testOptionalClearedDoesNotBlockPublish(): void
    {
        $rows = [
            ['store_id' => 1, 'locale' => '', 'cleared' => true, 'value' => null, 'is_required' => false],
        ];

        self::assertSame([], $this->resolver->publishDiagnostics($rows, 1, ''));
    }

    public function testRejectsNegativeStoreId(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->resolver->resolveAttribute([], -1, '');
    }
}

<?php

declare(strict_types=1);

namespace Weline\Review\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Review\Api\BuyerLooksGalleryInterface;
use Weline\Review\Service\BuyerLooksGalleryService;

final class BuyerLooksGalleryContractTest extends TestCase
{
    public function testInterfaceAndServiceExposeApprovedImageLooks(): void
    {
        $iface = (string)file_get_contents(dirname(__DIR__, 3) . '/Api/BuyerLooksGalleryInterface.php');
        self::assertStringContainsString('function galleryItems(', $iface);
        self::assertStringContainsString('Buyer looks (买家秀) is a presentation surface', $iface);

        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/BuyerLooksGalleryService.php');
        self::assertStringContainsString('public function galleryItems(', $source);
        self::assertStringContainsString("ProductReview::STATUS_APPROVED", $source);
        self::assertStringContainsString("!== 'image'", $source);
        self::assertStringContainsString('#product-reviews', $source);
        self::assertStringContainsString('resolveStorefrontProductId', $source);
        self::assertStringContainsString('findByGlobalUuid', $source);
        self::assertTrue(method_exists(BuyerLooksGalleryService::class, 'galleryItems'));
        self::assertTrue(method_exists(BuyerLooksGalleryInterface::class, 'galleryItems'));
    }

    public function testModuleProvidesBuyerLooksGallery(): void
    {
        $module = include dirname(__DIR__, 3) . '/etc/module.php';
        self::assertIsArray($module);
        self::assertSame(
            BuyerLooksGalleryService::class,
            $module['provides'][BuyerLooksGalleryInterface::class] ?? null,
        );
    }

    public function testBoundaryDocExists(): void
    {
        $doc = (string)file_get_contents(dirname(__DIR__, 3) . '/doc/买家秀与评论.md');
        self::assertStringContainsString('买家秀', $doc);
        self::assertStringContainsString('展示面', $doc);
        self::assertStringContainsString('生产与审核源', $doc);
        self::assertStringContainsString('BuyerLooksGalleryInterface', $doc);
    }
}

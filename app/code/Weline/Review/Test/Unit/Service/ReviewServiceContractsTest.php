<?php

declare(strict_types=1);

namespace Weline\Review\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Review\Api\BuyerLooksGalleryInterface;
use Weline\Review\Api\ReviewSeoFactsInterface;
use Weline\Review\Service\BuyerLooksGalleryService;
use Weline\Review\Service\ReviewService;

/**
 * Aggregated contract suite (merged from 3 *ContractTest files).
 * Observe one overall pass/fail via testAllReviewServiceContractsPass().
 */
final class ReviewServiceContractsTest extends TestCase
{
    public function testAllReviewServiceContractsPass(): void
    {
        $failures = [];
        foreach ($this->contractChecks() as $name => $fn) {
            try {
                $fn();
            } catch (\Throwable $e) {
                $failures[] = $name . ": " . $e->getMessage();
            }
        }
        self::assertSame([], $failures, implode("\n", $failures));
    }

    /** @return array<string, callable(): void> */
    private function contractChecks(): array
    {
        return [
            'BuyerLooksGallery::testInterfaceAndServiceExposeApprovedImageLooks' => function () {
            $this->assert_BuyerLooksGallery_testInterfaceAndServiceExposeApprovedImageLooks();
        },
            'BuyerLooksGallery::testModuleProvidesBuyerLooksGallery' => function () {
            $this->assert_BuyerLooksGallery_testModuleProvidesBuyerLooksGallery();
        },
            'BuyerLooksGallery::testBoundaryDocExists' => function () {
            $this->assert_BuyerLooksGallery_testBoundaryDocExists();
        },
            'ReviewAggregatesForExternalUuids::testInterfaceAndServiceExposeBatchAggregates' => function () {
            $this->assert_ReviewAggregatesForExternalUuids_testInterfaceAndServiceExposeBatchAggregates();
        },
            'ReviewFormSchemaI18nPrefetch::testFormPrefetchesSchemaPhrasesAndBindsModules' => function () {
            $this->assert_ReviewFormSchemaI18nPrefetch_testFormPrefetchesSchemaPhrasesAndBindsModules();
        },
            'ReviewFormSchemaI18nPrefetch::testFormSchemaSourcePhrasesCoverBlogAndProductLabels' => function () {
            $this->assert_ReviewFormSchemaI18nPrefetch_testFormSchemaSourcePhrasesCoverBlogAndProductLabels();
        },
        ];
    }

private function assert_BuyerLooksGallery_testInterfaceAndServiceExposeApprovedImageLooks(): void
{
    $iface = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Review' . '/Api/BuyerLooksGalleryInterface.php');
    self::assertStringContainsString('function galleryItems(', $iface);
    self::assertStringContainsString('Buyer looks (买家秀) is a presentation surface', $iface);
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Review' . '/Service/BuyerLooksGalleryService.php');
    self::assertStringContainsString('public function galleryItems(', $source);
    self::assertStringContainsString("ProductReview::STATUS_APPROVED", $source);
    self::assertStringContainsString("!== 'image'", $source);
    self::assertStringContainsString('#product-reviews', $source);
    self::assertStringContainsString('forReviews', $source);
    self::assertStringContainsString('resolveStorefrontProductIds', $source);
    self::assertStringContainsString('filterSellableProductIds', $source);
    self::assertStringContainsString('StorefrontCatalogProductVisibility', $source);
    self::assertStringContainsString(':s\' . $storeId . \':c\' . $channelId', $source);
    self::assertStringContainsString('StorefrontScopeHotCache', $source);
    self::assertStringContainsString('mapIdsByGlobalUuids', $source);
    self::assertStringContainsString('do not resurrect per-uuid findByGlobalUuid', $source);
    // Cross-site PDP fallback to website 0 must stay removed.
    self::assertStringNotContainsString('findByGlobalUuid(0, $uuid)', $source);
    self::assertStringContainsString('Never fall back to website 0', $source);
    // Soft N+1 compat loop must stay removed (architecture: batch-only).
    self::assertStringNotContainsString('foreach ($normalized as $uuid)', $source);
    self::assertTrue(method_exists(BuyerLooksGalleryService::class, 'galleryItems'));
    self::assertTrue(method_exists(BuyerLooksGalleryInterface::class, 'galleryItems'));
}

private function assert_BuyerLooksGallery_testModuleProvidesBuyerLooksGallery(): void
{
    $module = include '/Users/weline/Project/Official/框架/app/code/Weline/Review' . '/etc/module.php';
    self::assertIsArray($module);
    self::assertSame(BuyerLooksGalleryService::class, $module['provides'][BuyerLooksGalleryInterface::class] ?? null);
}

private function assert_BuyerLooksGallery_testBoundaryDocExists(): void
{
    $doc = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Review' . '/doc/买家秀与评论.md');
    self::assertStringContainsString('买家秀', $doc);
    self::assertStringContainsString('展示面', $doc);
    self::assertStringContainsString('生产与审核源', $doc);
    self::assertStringContainsString('BuyerLooksGalleryInterface', $doc);
    self::assertStringContainsString('editor_context.scope.identity.website_id', $doc);
}

private function assert_ReviewAggregatesForExternalUuids_testInterfaceAndServiceExposeBatchAggregates(): void
{
    $iface = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Review' . '/Api/ReviewSeoFactsInterface.php');
    self::assertStringContainsString('function aggregatesForExternalUuids(', $iface);
    self::assertStringContainsString('@return array<string, array{review_count:int, average_rating:float}>', $iface);
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Review' . '/Service/ReviewService.php');
    self::assertStringContainsString('public function aggregatesForExternalUuids(', $source);
    self::assertStringContainsString("->group(ProductReview::schema_fields_ENTITY_UUID)", $source);
    self::assertStringContainsString("ProductReview::STATUS_APPROVED", $source);
    self::assertTrue(method_exists(ReviewService::class, 'aggregatesForExternalUuids'));
    self::assertTrue(method_exists(ReviewSeoFactsInterface::class, 'aggregatesForExternalUuids'));
}

private function assert_ReviewFormSchemaI18nPrefetch_testFormPrefetchesSchemaPhrasesAndBindsModules(): void
{
    $source = (string) file_get_contents('/Users/weline/Project/Official/框架/app/code/Weline/Review' . '/Service/ReviewService.php');
    self::assertStringContainsString('formSchemaSourcePhrases', $source);
    self::assertStringContainsString('Parser::prefetchWords(self::formSchemaSourcePhrases())', $source);
    self::assertStringContainsString("addModule('Weline_Review')", $source);
    self::assertStringContainsString("addModule('Weline_Blog')", $source);
    self::assertStringContainsString('标题（选填）', $source);
    self::assertStringContainsString('添加图片', $source);
    self::assertStringContainsString('匿名展示这条评论', $source);
    self::assertStringNotContainsString('_debug_title', $source);
}

private function assert_ReviewFormSchemaI18nPrefetch_testFormSchemaSourcePhrasesCoverBlogAndProductLabels(): void
{
    $phrases = ReviewService::formSchemaSourcePhrases();
    foreach (['标题（选填）', '评论内容', '添加图片', '添加视频', '一句话概括您的观点', '一句话概括您的体验', '总体评分', '邮箱（游客选填，不公开）'] as $required) {
        self::assertContains($required, $phrases);
    }
}
}

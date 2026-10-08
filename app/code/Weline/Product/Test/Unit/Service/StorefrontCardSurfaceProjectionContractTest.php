<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Product\Repository\OfferRepository;
use Weline\Product\Service\StorefrontCatalogViewService;
use Weline\Product\Service\StorefrontProductDetailProjector;

/**
 * Cold shelf cards must not SELECT * / carry description-meta fat in summary bags.
 */
final class StorefrontCardSurfaceProjectionContractTest extends TestCase
{
    public function testOfferRepositoryExposesCardColumnAllowlist(): void
    {
        $fields = OfferRepository::storefrontCardOfferFields();
        self::assertContains('offer_id', $fields);
        self::assertContains('product_id', $fields);
        self::assertContains('global_offer_uuid', $fields);
        self::assertContains('sku', $fields);
        self::assertNotContains('type_config_json', $fields);
        self::assertNotContains('cas_token', $fields);

        $repoSource = (string)file_get_contents(dirname(__DIR__, 3) . '/Repository/OfferRepository.php');
        self::assertStringContainsString('->fields(self::storefrontCardOfferFields())', $repoSource);
    }

    public function testSummaryCacheGenerationIsCard3(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/StorefrontCatalogViewService.php');
        self::assertStringContainsString("'summary-card3'", $source);
        self::assertStringContainsString('toCardSurfaceRow', $source);
        self::assertStringContainsString('// Cold path: page size tracks remaining need', $source);
    }

    public function testListingSummaryDoesNotReattachDescriptionMeta(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/StorefrontProductDetailProjector.php');
        $methodPos = strpos($source, 'function projectListingSummary');
        self::assertNotFalse($methodPos);
        $chunk = substr($source, $methodPos, 2200);
        self::assertStringContainsString('Cold card surface', $chunk);
        self::assertStringNotContainsString("'description' =>", $chunk);
        self::assertStringNotContainsString("'meta_description' =>", $chunk);
    }

    public function testHomepageShelfPlanUsesBoundedPools(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/StorefrontProductWidgetCatalog.php');
        self::assertStringContainsString('buildFeaturedCandidateCards(16)', $source);
        self::assertStringContainsString('buildDealCandidateCards(12)', $source);
        self::assertStringContainsString('bestSellerCards(16, false)', $source);
    }
}

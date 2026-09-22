<?php

declare(strict_types=1);

namespace Weline\Faq\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Faq\Service\FaqHubContent;
use Weline\Faq\Service\FaqSeedCopyCatalog;

final class FaqHubContentDbPreferContractTest extends TestCase
{
    public function testFaqsForLocaleSourcePrefersDbLoaderOverCatalogOnly(): void
    {
        $src = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/FaqHubContent.php');
        self::assertStringContainsString('loadSiteHubFromDb', $src);
        self::assertStringContainsString('listForEntity', $src);
        self::assertStringContainsString('SiteFaqTypeProvider::ENTITY_UUID', $src);
        // Catalog remains fallback when DB empty — but must not be the only path.
        self::assertStringContainsString('FaqSeedCopyCatalog::hubForLocale', $src);
        self::assertLessThan(
            strpos($src, 'FaqSeedCopyCatalog::hubForLocale') ?: PHP_INT_MAX,
            strpos($src, 'loadSiteHubFromDb') ?: PHP_INT_MAX
        );
    }

    public function testCatalogStillHasEightHubRowsForMaintainedLocales(): void
    {
        foreach (FaqSeedCopyCatalog::maintainedLocales() as $locale) {
            self::assertCount(8, FaqSeedCopyCatalog::hubForLocale($locale), $locale);
        }
    }

    public function testHubContentClassIsFinalAndWired(): void
    {
        $ref = new \ReflectionClass(FaqHubContent::class);
        self::assertTrue($ref->isFinal());
        self::assertTrue($ref->hasMethod('faqsForLocale'));
        self::assertTrue($ref->hasMethod('faqs'));
    }
}

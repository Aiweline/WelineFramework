<?php

declare(strict_types=1);

namespace {
    if (!function_exists('__')) {
        function __(string $text, array $arguments = []): string
        {
            foreach (array_values($arguments) as $index => $argument) {
                $text = str_replace('%{' . ($index + 1) . '}', (string)$argument, $text);
            }

            return $text;
        }
    }
}

namespace Weline\Faq\Test\Unit\Service {

use PHPUnit\Framework\TestCase;
use Weline\Faq\Service\ProductFaqTypeProvider;
use Weline\Faq\Service\SiteFaqTypeProvider;

final class ProductFaqTypeProviderTest extends TestCase
{
    public function testProductAndSiteTypeCodes(): void
    {
        self::assertSame('product', (new ProductFaqTypeProvider())->typeCode());
        self::assertSame('site', (new SiteFaqTypeProvider())->typeCode());
    }

    public function testSiteProviderNormalizesEmptyAndSiteUuid(): void
    {
        $provider = new SiteFaqTypeProvider();
        self::assertSame(
            ['entity_id' => 0, 'entity_uuid' => 'site'],
            $provider->resolveEntity('site'),
        );
        self::assertSame(
            ['entity_id' => 0, 'entity_uuid' => 'site'],
            $provider->resolveEntity(''),
        );
        self::assertNull($provider->resolveEntity('other'));
    }

    public function testProductProviderReturnsNullForBlankUuid(): void
    {
        self::assertNull((new ProductFaqTypeProvider())->resolveEntity(''));
    }

    public function testProductProviderOfferThenProductUuidContract(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/ProductFaqTypeProvider.php');
        self::assertStringContainsString('resolveByOfferUuid', $source);
        self::assertStringContainsString('resolveByProductUuid', $source);
        self::assertStringContainsString('globalProductUuid', $source);
        self::assertStringContainsString("return 'product'", $source);

        $registry = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/FaqTypeRegistry.php');
        self::assertStringContainsString('ProductFaqTypeProvider', $registry);
        self::assertStringContainsString('SiteFaqTypeProvider', $registry);
        self::assertStringContainsString('TemplateFaqTypeProvider', $registry);
        self::assertStringContainsString('FaqTypeProvider', $registry);

        $extends = include dirname(__DIR__, 3) . '/extends.php';
        self::assertArrayHasKey('FaqTypeProvider', $extends['extends'] ?? []);
    }
}
}

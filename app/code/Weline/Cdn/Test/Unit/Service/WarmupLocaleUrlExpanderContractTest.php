<?php

declare(strict_types=1);

namespace Weline\Cdn\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Cdn\Service\WarmupLocaleUrlExpander;
use Weline\I18n\Service\Seo\LocalizedUrlBuilder;

final class WarmupLocaleUrlExpanderContractTest extends TestCase
{
    public function testExpanderUsesLocalizedUrlBuilderAcrossLocales(): void
    {
        $path = dirname(__DIR__, 3) . '/Service/WarmupLocaleUrlExpander.php';
        $src = (string)file_get_contents($path);
        $this->assertStringContainsString('LocalizedUrlBuilderInterface', $src);
        $this->assertStringContainsString('getWebsiteLanguageCodes', $src);
        $this->assertStringContainsString('WebsiteData::defaultLanguageForWebsite', $src);
        $this->assertStringContainsString('WebsiteData::defaultCurrencyForWebsite', $src);
        $this->assertStringNotContainsString('Website::schema_fields_ID', $src);
        $this->assertStringContainsString('expandRoute', $src);
        $this->assertStringContainsString('Visitor', $src); // documents deferred heat ranking
    }

    public function testLocalizedUrlBuilderOmitsDefaultLocaleSegment(): void
    {
        $builder = new LocalizedUrlBuilder();
        $default = $builder->build('https://example.com', '/product/demo', 'zh_Hans_CN', 'zh_Hans_CN', null, 'CNY');
        $en = $builder->build('https://example.com', '/product/demo', 'en_US', 'zh_Hans_CN', null, 'CNY');
        $this->assertSame('https://example.com/product/demo', $default);
        $this->assertSame('https://example.com/en_US/product/demo', $en);
    }

    public function testProvidersExpandLocalesInSource(): void
    {
        $fpc = (string)file_get_contents(dirname(__DIR__, 3) . '/WarmupProvider/FpcExtraDeclaredUrls.php');
        $this->assertStringContainsString('WarmupLocaleUrlExpander', $fpc);
        $this->assertStringContainsString('覆盖站点已启用语种', $fpc);

        $product = (string)file_get_contents(
            dirname(__DIR__, 4) . '/Product/extends/module/Weline_Cdn/ProductHeatUrls.php'
        );
        $this->assertStringContainsString('WarmupLocaleUrlExpander', $product);
        $this->assertStringContainsString('站点已启用语种', $product);
    }
}

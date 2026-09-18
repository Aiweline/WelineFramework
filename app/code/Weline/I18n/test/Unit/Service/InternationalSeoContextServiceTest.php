<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Manager\ObjectManager;
use Weline\Framework\Service\Query\FrameworkQueryService;
use Weline\I18n\Service\ActiveLocaleCodeProvider;
use Weline\I18n\Service\Seo\InternationalSeoContextService;
use Weline\I18n\Service\Seo\LocalizedUrlBuilder;

class InternationalSeoContextServiceTest extends TestCase
{
    public function testBuildsAlternatesFromInstalledLocalesAndCanonicalPath(): void
    {
        $service = $this->service(['zh_Hans_CN', 'en_US']);
        $template = new InternationalSeoTemplateStub([
            'seo' => ['default_locale' => 'zh_Hans_CN'],
        ]);

        $context = $service->build($template, [
            'locale' => 'en_US',
            'canonical_url' => 'https://example.com/en_US/products/item',
            'url' => 'https://example.com/en_US/products/item',
        ]);

        self::assertSame('en_US', $context['locale']);
        self::assertSame('en-US', $context['html_locale']);
        self::assertSame(['zh-Hans-CN', 'en-US'], $context['available_languages']);
        self::assertSame('https://example.com/products/item', $context['alternates']['zh_Hans_CN']);
        self::assertSame('https://example.com/en_US/products/item', $context['alternates']['en_US']);
        self::assertSame('https://example.com/products/item', $context['alternates']['x-default']);
    }

    public function testAppliesLocalizedSeoOverridesForCurrentLocale(): void
    {
        $service = $this->service(['zh_Hans_CN', 'en_US']);
        $template = new InternationalSeoTemplateStub([
            'seo' => ['default_locale' => 'zh_Hans_CN'],
            'i18n_seo' => [
                'en-US' => [
                    'title' => 'English title',
                    'description' => 'English description',
                    'canonical_url' => '/en_US/example',
                    'image' => '/media/example-en.jpg',
                ],
            ],
            'i18n_alternates' => [
                'zh_Hans_CN' => '/example',
                'en_US' => '/en_US/example',
            ],
        ]);

        $context = $service->build($template, [
            'locale' => 'en_US',
            'canonical_url' => 'https://example.com/en_US/example',
            'url' => 'https://example.com/en_US/example',
        ]);

        self::assertSame('English title', $context['title']);
        self::assertSame('English description', $context['description']);
        self::assertSame('https://example.com/en_US/example', $context['canonical_url']);
        self::assertSame('https://example.com/media/example-en.jpg', $context['image']);
        self::assertSame('https://example.com/example', $context['alternates']['zh_Hans_CN']);
    }

    public function testDefaultWebsiteZeroUsesWebsiteLocalesInsteadOfPlatformCatalog(): void
    {
        $originalQueryService = ObjectManager::_getInstance(FrameworkQueryService::class);
        $queryService = new class extends FrameworkQueryService {
            public function __construct()
            {
            }

            public function execute(
                ?string $provider = null,
                ?string $operation = null,
                array $params = [],
                string $area = 'frontend',
            ): mixed {
                if ($provider !== 'websites' || (int)($params['website_id'] ?? -1) !== 0) {
                    return [];
                }

                return match ($operation) {
                    'getWebsiteLanguageCodes' => ['zh_Hans_CN', 'en_US'],
                    'getWebsiteById' => ['default_language' => 'zh_Hans_CN'],
                    default => [],
                };
            }
        };
        ObjectManager::setInstance(FrameworkQueryService::class, $queryService);

        try {
            $service = $this->service(['zh_Hans_CN', 'en_US', 'de_DE']);
            $template = new InternationalSeoTemplateStub([
                'website_id' => 0,
            ]);

            $context = $service->build($template, [
                'locale' => 'en_US',
                'canonical_url' => 'https://example.com/en_US/products/item',
            ]);

            self::assertSame(['zh-Hans-CN', 'en-US'], $context['available_languages']);
            self::assertArrayHasKey('zh_Hans_CN', $context['alternates']);
            self::assertArrayHasKey('en_US', $context['alternates']);
            self::assertArrayNotHasKey('de_DE', $context['alternates']);
        } finally {
            if ($originalQueryService instanceof FrameworkQueryService) {
                ObjectManager::setInstance(FrameworkQueryService::class, $originalQueryService);
            } else {
                ObjectManager::removeInstance(FrameworkQueryService::class);
            }
        }
    }

    public function testSelfHreflangEqualsCanonicalWhenDefaultCurrencyOmittedFromPath(): void
    {
        $service = $this->service(['zh_Hans_CN', 'bn_BD']);
        $canonical = 'https://shop.test/bn_BD/product/demo-sku';
        $context = $service->build(new InternationalSeoTemplateStub([
            'seo' => ['default_locale' => 'zh_Hans_CN'],
        ]), [
            'locale' => 'bn_BD',
            'canonical_url' => $canonical,
            'url' => $canonical,
        ]);

        self::assertSame($canonical, $context['alternates']['bn_BD']);
        self::assertStringNotContainsString('/USD/', (string)$context['alternates']['bn_BD']);
        self::assertSame('https://shop.test/product/demo-sku', $context['alternates']['zh_Hans_CN']);
        self::assertSame('https://shop.test/product/demo-sku', $context['alternates']['x-default']);
    }

    public function testNonDefaultCurrencyFromCanonicalIsPreservedAcrossHreflang(): void
    {
        $service = $this->service(['zh_Hans_CN', 'bn_BD']);
        $canonical = 'https://shop.test/EUR/bn_BD/product/demo-sku';
        $context = $service->build(new InternationalSeoTemplateStub([
            'seo' => ['default_locale' => 'zh_Hans_CN'],
        ]), [
            'locale' => 'bn_BD',
            'canonical_url' => $canonical,
            'url' => $canonical,
        ]);

        self::assertSame($canonical, $context['alternates']['bn_BD']);
        self::assertSame('https://shop.test/EUR/product/demo-sku', $context['alternates']['zh_Hans_CN']);
        self::assertSame('https://shop.test/EUR/product/demo-sku', $context['alternates']['x-default']);
    }

    public function testDoesNotReinjectUserCurrencyWhenCanonicalOmitsDefaultCurrency(): void
    {
        // Regression: even if request env claims user.currency=USD, hreflang must follow
        // the canonical path (no currency segment for site-default USD) — never reinject.
        $_SERVER['WELINE_USER_CURRENCY'] = 'USD';
        try {
            $service = $this->service(['zh_Hans_CN', 'bn_BD']);
            $canonical = 'https://shop.test/bn_BD/product/demo-sku';
            $context = $service->build(new InternationalSeoTemplateStub([
                'seo' => ['default_locale' => 'zh_Hans_CN'],
            ]), [
                'locale' => 'bn_BD',
                'canonical_url' => $canonical,
                'url' => $canonical,
            ]);

            self::assertSame($canonical, $context['alternates']['bn_BD']);
            foreach ($context['alternates'] as $href) {
                self::assertStringNotContainsString('/USD/', (string)$href);
            }
        } finally {
            unset($_SERVER['WELINE_USER_CURRENCY']);
        }
    }

    /**
     * @param string[] $locales
     */
    private function service(array $locales): InternationalSeoContextService
    {
        return new InternationalSeoContextService(
            $this->localeProvider($locales),
            new LocalizedUrlBuilder(),
        );
    }

    /**
     * @param string[] $locales
     */
    private function localeProvider(array $locales): ActiveLocaleCodeProvider
    {
        $provider = $this->getMockBuilder(ActiveLocaleCodeProvider::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getInstalledActiveCodes'])
            ->getMock();
        $provider->method('getInstalledActiveCodes')->willReturn($locales);
        return $provider;
    }
}

final class InternationalSeoTemplateStub
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private array $data = [])
    {
    }

    public function getData(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function setData(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }
}

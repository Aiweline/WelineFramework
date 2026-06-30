<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Service\ActiveLocaleCodeProvider;
use Weline\I18n\Service\Seo\InternationalSeoContextService;

class InternationalSeoContextServiceTest extends TestCase
{
    public function testBuildsAlternatesFromInstalledLocalesAndCanonicalPath(): void
    {
        $service = new InternationalSeoContextService($this->localeProvider(['zh_Hans_CN', 'en_US']));
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
        $service = new InternationalSeoContextService($this->localeProvider(['zh_Hans_CN', 'en_US']));
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

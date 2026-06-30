<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Service\Head\HeadRenderer;
use Weline\Seo\Service\Head\PageSeoContextResolver;

class HeadRendererI18nTest extends TestCase
{
    public function testRendersInternationalSeoHeadTags(): void
    {
        $resolver = $this->getMockBuilder(PageSeoContextResolver::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolve'])
            ->getMock();
        $resolver->method('resolve')->willReturn([
            'title' => 'English title',
            'description' => 'English description',
            'canonical_url' => 'https://example.com/en_US/example',
            'url' => 'https://example.com/en_US/example',
            'locale' => 'en_US',
            'html_locale' => 'en-US',
            'og_locale' => 'en_US',
            'available_languages' => ['zh-Hans-CN', 'en-US'],
            'alternates' => [
                'zh_Hans_CN' => 'https://example.com/example',
                'en_US' => 'https://example.com/en_US/example',
                'x-default' => 'https://example.com/example',
            ],
            'site_name' => 'Example',
            'organization' => ['name' => 'Example', 'url' => 'https://example.com/'],
        ]);

        $html = (new HeadRenderer($resolver))->render(new HeadRendererTemplateStub());

        self::assertStringContainsString('<link rel="alternate" hreflang="zh-Hans-CN" href="https://example.com/example">', $html);
        self::assertStringContainsString('<link rel="alternate" hreflang="en-US" href="https://example.com/en_US/example">', $html);
        self::assertStringContainsString('<link rel="alternate" hreflang="x-default" href="https://example.com/example">', $html);
        self::assertStringContainsString('<meta property="og:locale" content="en_US">', $html);
        self::assertStringContainsString('<meta property="og:locale:alternate" content="zh_Hans_CN">', $html);
        self::assertStringContainsString('"inLanguage": "en-US"', $html);
        self::assertStringContainsString('"availableLanguage": [', $html);
    }
}

final class HeadRendererTemplateStub
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function getData(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function setData(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }
}

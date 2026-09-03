<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Framework\Runtime\RequestContext;
use Weline\Seo\Service\Head\HeadRenderer;
use Weline\Seo\Service\Head\PageSeoContextResolver;

if (!defined('BP')) {
    define('BP', dirname(__DIR__, 7) . DIRECTORY_SEPARATOR);
}
require_once BP . 'app/autoload.php';
if (!defined('CLI')) {
    define('CLI', true);
}
if (!defined('PROD')) {
    define('PROD', false);
}

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

    public function testRendersHeadOnlyOnceAcrossTemplateObjectsWithinARequest(): void
    {
        $resolver = $this->getMockBuilder(PageSeoContextResolver::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['resolve'])
            ->getMock();
        $resolver->expects(self::exactly(2))->method('resolve')->willReturn([
            'title' => 'Example',
            'canonical_url' => 'https://example.com/',
            'url' => 'https://example.com/',
            'site_name' => 'Example',
            'organization' => ['name' => 'Example', 'url' => 'https://example.com/'],
        ]);
        $renderer = new HeadRenderer($resolver);

        RequestContext::init();
        try {
            $first = $renderer->render(new HeadRendererTemplateStub());
            $duplicate = $renderer->render(new HeadRendererTemplateStub());

            self::assertSame(1, substr_count($first, '<link rel="canonical"'));
            self::assertSame('', $duplicate);
        } finally {
            RequestContext::cleanup();
        }

        RequestContext::init();
        try {
            $nextRequest = $renderer->render(new HeadRendererTemplateStub());
            self::assertSame(1, substr_count($nextRequest, '<link rel="canonical"'));
        } finally {
            RequestContext::cleanup();
        }
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

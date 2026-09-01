<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Blog\Service\BlogPostSlugService;
use Weline\I18n\Service\AiTranslationConfig;
use Weline\I18n\Service\I18nAiTranslationAdapter;

final class BlogPostSlugServiceTest extends TestCase
{
    public function testSuggestSlugAutoSlugifiesTitle(): void
    {
        $service = new BlogPostSlugService(
            $this->createMock(I18nAiTranslationAdapter::class),
            $this->createMock(AiTranslationConfig::class),
        );

        $result = $service->suggestSlug('Hello World Post', 'en_US', false);
        self::assertSame('hello-world-post', $result['slug']);
        self::assertSame('auto', $result['mode']);
    }

    public function testSuggestSlugAiUsesLocaleTransliterationForChinese(): void
    {
        $ai = $this->createMock(I18nAiTranslationAdapter::class);
        $ai->expects(self::never())->method('translateBatch');

        $config = $this->createMock(AiTranslationConfig::class);
        $config->method('getSourceLocale')->willReturn('zh_Hans_CN');

        $service = new BlogPostSlugService($ai, $config);
        $result = $service->suggestSlug('我在吃顿饭', 'zh_Hans_CN', true);

        self::assertSame('wo-zai-chi-dun-fan', $result['slug']);
        self::assertSame('ai', $result['mode']);
    }

    public function testSuggestSlugAiTranslatesToEnglishLocale(): void
    {
        $ai = $this->createMock(I18nAiTranslationAdapter::class);
        $ai->expects(self::once())
            ->method('translateBatch')
            ->with(['你好世界'], 'zh_Hans_CN', 'en_US')
            ->willReturn([
                'success' => true,
                'translations' => ['你好世界' => 'Hello World'],
                'errors' => [],
            ]);

        $config = $this->createMock(AiTranslationConfig::class);
        $config->method('getSourceLocale')->willReturn('zh_Hans_CN');

        $service = new BlogPostSlugService($ai, $config);
        $result = $service->suggestSlug('你好世界', 'en_US', true);

        self::assertSame('hello-world', $result['slug']);
        self::assertSame('ai', $result['mode']);
    }
}

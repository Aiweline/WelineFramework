<?php

declare(strict_types=1);

namespace Weline\Blog\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Blog\Model\Post;
use Weline\Blog\Model\Post\LocalDescription;
use Weline\Blog\Service\BlogKeywordLocalizer;
use Weline\I18n\Api\Localization\LocalModel;

final class BlogKeywordLocalizerContractTest extends TestCase
{
    public function testPostLocalDescriptionIsLocalModelWithKeywordsField(): void
    {
        self::assertTrue(is_subclass_of(LocalDescription::class, LocalModel::class));
        self::assertSame(Post::schema_fields_ID, LocalDescription::schema_fields_ID);
        self::assertSame(Post::schema_fields_KEYWORDS, LocalDescription::schema_fields_KEYWORDS);
        self::assertSame('weline_blog_post_local', LocalDescription::schema_table);
    }

    public function testEnglishLocaleKeepsLatinAndDropsUntranslatedHan(): void
    {
        $localizer = new BlogKeywordLocalizer();
        $tags = $localizer->localizeTags(
            '俄罗斯族服饰,Russian dress,民族服装',
            'en_US',
            static fn(string $text): string => $text,
        );

        self::assertSame(['Russian dress'], $tags);
    }

    public function testEnglishLocaleUsesDictionaryTranslationForHanTags(): void
    {
        $localizer = new BlogKeywordLocalizer();
        $map = [
            '俄罗斯族服饰' => 'Russian ethnic dress',
            '民族服装' => 'Ethnic clothing',
        ];
        $tags = $localizer->localizeTags(
            '俄罗斯族服饰,Russian dress,民族服装',
            'en_US',
            static fn(string $text): string => $map[$text] ?? $text,
        );

        self::assertSame(['Russian ethnic dress', 'Russian dress', 'Ethnic clothing'], $tags);
    }

    public function testChineseLocaleKeepsHanTags(): void
    {
        $localizer = new BlogKeywordLocalizer();
        $tags = $localizer->localizeTags(
            '俄罗斯族服饰,Russian dress,民族服装',
            'zh_Hans_CN',
            static fn(string $text): string => $text,
        );

        self::assertSame(['俄罗斯族服饰', '民族服装'], $tags);
    }

    public function testLocalKeywordsBypassScriptFilter(): void
    {
        $localizer = new BlogKeywordLocalizer();
        $out = $localizer->localizeKeywordsString(
            '俄罗斯族服饰,Russian dress,民族服装',
            'en_US',
            'Russian ethnic dress,Ethnic clothing',
        );

        self::assertSame('Russian ethnic dress,Ethnic clothing', $out);
    }

    public function testContentResolverWiresKeywordLocalizer(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/BlogContentResolver.php');
        self::assertStringContainsString('BlogKeywordLocalizer', $source);
        self::assertStringContainsString('LocalDescription', $source);
        self::assertStringContainsString('resolveKeywords', $source);
    }
}

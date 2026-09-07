<?php

declare(strict_types=1);

namespace Weline\Theme\Test\Unit;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Api\Translation\DictionaryRepositoryInterface;
use Weline\I18n\Service\TranslationResolver;

final class BlogCategoryLayoutTranslationTest extends TestCase
{
    public function testBlogCategoryLayoutNameHasAnEnglishTranslation(): void
    {
        $dictionary = $this->createStub(DictionaryRepositoryInterface::class);
        $resolver = new TranslationResolver(dictionaryRepository: $dictionary);

        self::assertSame('Blog Category Page', $resolver->translate('博客分类页布局', 'en_US', ['Weline_Theme']));
        self::assertSame('博客分类页布局', $resolver->translate('博客分类页布局', 'zh_Hans_CN', ['Weline_Theme']));
    }
}

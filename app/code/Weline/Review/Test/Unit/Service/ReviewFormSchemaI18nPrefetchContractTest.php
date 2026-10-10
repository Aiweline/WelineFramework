<?php

declare(strict_types=1);

namespace Weline\Review\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\Review\Service\ReviewService;

final class ReviewFormSchemaI18nPrefetchContractTest extends TestCase
{
    public function testFormPrefetchesSchemaPhrasesAndBindsModules(): void
    {
        $source = (string)file_get_contents(dirname(__DIR__, 3) . '/Service/ReviewService.php');

        self::assertStringContainsString('formSchemaSourcePhrases', $source);
        self::assertStringContainsString('Parser::prefetchWords(self::formSchemaSourcePhrases())', $source);
        self::assertStringContainsString("addModule('Weline_Review')", $source);
        self::assertStringContainsString("addModule('Weline_Blog')", $source);
        self::assertStringContainsString('标题（选填）', $source);
        self::assertStringContainsString('添加图片', $source);
        self::assertStringContainsString('匿名展示这条评论', $source);
        self::assertStringNotContainsString('_debug_title', $source);
    }

    public function testFormSchemaSourcePhrasesCoverBlogAndProductLabels(): void
    {
        $phrases = ReviewService::formSchemaSourcePhrases();
        foreach ([
            '标题（选填）',
            '评论内容',
            '添加图片',
            '添加视频',
            '一句话概括您的观点',
            '一句话概括您的体验',
            '总体评分',
            '邮箱（游客选填，不公开）',
        ] as $required) {
            self::assertContains($required, $phrases);
        }
    }
}

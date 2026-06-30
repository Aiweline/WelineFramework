<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Structure;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Structure\Faq\FaqStructureNormalizer;

class FaqStructureNormalizerTest extends TestCase
{
    public function testNormalizesQuestionAnswerAliases(): void
    {
        $normalizer = new FaqStructureNormalizer();
        $faqs = $normalizer->normalize([
            ['q' => '问题一', 'a' => '答案一'],
            ['title' => '问题二', 'text' => '答案二'],
            ['name' => '问题三', 'acceptedAnswer' => ['text' => '答案三']],
        ]);

        self::assertSame([
            ['question' => '问题一', 'answer' => '答案一'],
            ['question' => '问题二', 'answer' => '答案二'],
            ['question' => '问题三', 'answer' => '答案三'],
        ], $faqs);
    }

    public function testFiltersEmptyItemsAndDedupesByQuestion(): void
    {
        $normalizer = new FaqStructureNormalizer();
        $faqs = $normalizer->mergeAndNormalize(
            [
                ['question' => '重复问题', 'answer' => '答案 A'],
                ['question' => '有效问题', 'answer' => '答案 B'],
            ],
            [
                ['question' => '重复问题', 'answer' => '答案 C'],
                ['question' => '', 'answer' => '无效'],
            ]
        );

        self::assertCount(2, $faqs);
        self::assertSame('重复问题', $faqs[0]['question']);
        self::assertSame('答案 A', $faqs[0]['answer']);
        self::assertSame('有效问题', $faqs[1]['question']);
    }
}

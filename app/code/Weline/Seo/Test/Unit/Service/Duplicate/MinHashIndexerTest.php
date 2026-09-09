<?php

declare(strict_types=1);

namespace Weline\Seo\Test\Unit\Service\Duplicate;

use PHPUnit\Framework\TestCase;
use Weline\Seo\Service\Duplicate\MinHashIndexer;

final class MinHashIndexerTest extends TestCase
{
    public function testIdenticalTextsScoreAsDuplicate(): void
    {
        $text = \str_repeat('如何选择跑步鞋需要根据足弓与路况以及训练强度来判断，并记录实测脚感。', 12);
        $indexer = new MinHashIndexer();
        $pairs = $indexer->findNearDuplicates([
            ['id' => 1, 'text' => $text, 'bucket_key' => 'blog|zh'],
            ['id' => 2, 'text' => $text, 'bucket_key' => 'blog|zh'],
        ]);

        self::assertNotEmpty($pairs);
        self::assertSame(MinHashIndexer::GRADE_DUPLICATE, $pairs[0]['grade']);
        self::assertGreaterThanOrEqual(0.85, $pairs[0]['jaccard']);
    }

    public function testUnrelatedTextsDoNotPair(): void
    {
        $a = \str_repeat('苹果手机摄影技巧与夜景模式设置说明以及构图建议和后期调色流程。', 12);
        $b = \str_repeat('企业财报分析与现金流折现估值方法以及资产负债表结构解读要点。', 12);
        $indexer = new MinHashIndexer();
        $pairs = $indexer->findNearDuplicates([
            ['id' => 'a', 'text' => $a, 'bucket_key' => 'blog|zh'],
            ['id' => 'b', 'text' => $b, 'bucket_key' => 'blog|zh'],
        ]);

        self::assertSame([], $pairs);
    }

    public function testDoesNotCrossBuckets(): void
    {
        $text = \str_repeat('同一篇关于跑步鞋选购指南的正文内容，包含足弓支撑与缓冲层材料对比。', 12);
        $indexer = new MinHashIndexer();
        $pairs = $indexer->findNearDuplicates([
            ['id' => 1, 'text' => $text, 'bucket_key' => 'blog|zh'],
            ['id' => 2, 'text' => $text, 'bucket_key' => 'product|zh'],
        ]);

        self::assertSame([], $pairs);
    }

    public function testSkipsShortText(): void
    {
        $indexer = new MinHashIndexer();
        $pairs = $indexer->findNearDuplicates([
            ['id' => 1, 'text' => '太短', 'bucket_key' => 'blog|zh'],
            ['id' => 2, 'text' => '太短', 'bucket_key' => 'blog|zh'],
        ], minChars: 200);

        self::assertSame([], $pairs);
    }

    public function testGradeThresholds(): void
    {
        $indexer = new MinHashIndexer();
        self::assertSame('duplicate', $indexer->grade(0.9));
        self::assertSame('suspect', $indexer->grade(0.75));
        self::assertSame('ok', $indexer->grade(0.5));
    }
}

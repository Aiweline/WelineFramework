<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Sample\Hanfu1688;

use PHPUnit\Framework\TestCase;
use Weline\Product\Sample\Hanfu1688\HanfuProductClassifier;

final class HanfuProductClassifierTest extends TestCase
{
    /** @dataProvider acceptedTitles */
    public function testAcceptsOnlyTitlesWithExplicitHanfuGarmentEvidence(string $title): void
    {
        $decision = (new HanfuProductClassifier())->classify($title);

        self::assertTrue($decision['accepted']);
        self::assertNotSame('', $decision['matched_term']);
        self::assertSame('explicit_hanfu_term', $decision['reason']);
    }

    /** @return iterable<string,array{string}> */
    public static function acceptedTitles(): iterable
    {
        yield '汉服直接证据' => ['儿童汉服女童夏季超仙齐胸襦裙'];
        yield '马面裙' => ['明制织金马面裙现货'];
        yield '宋裤' => ['宋制汉服百迭裙宋裤套装'];
        yield '圆领袍' => ['明制圆领袍男秋冬款'];
    }

    /** @dataProvider rejectedTitles */
    public function testRejectsAdjacentCostumesWithoutExplicitHanfuEvidence(string $title, string $reason): void
    {
        $decision = (new HanfuProductClassifier())->classify($title);

        self::assertFalse($decision['accepted']);
        self::assertSame('', $decision['matched_term']);
        self::assertSame($reason, $decision['reason']);
    }

    /** @return iterable<string,array{string,string}> */
    public static function rejectedTitles(): iterable
    {
        yield '校服' => ['幼儿园园服小学生校服书香中国风', 'no_explicit_hanfu_term'];
        yield '武术服' => ['儿童武术服少儿表演服唐装', 'exclude_performance_costume'];
        yield '茶服' => ['中式禅意茶服夏季薄款', 'no_explicit_hanfu_term'];
        yield '舞台服' => ['中国风大合唱舞台演出服', 'exclude_performance_costume'];
        yield '新中式无形制' => ['新中式女装夏季上衣', 'exclude_modern_hanfu_adjacent'];
        yield '新中式盖过马面裙' => ['新中式国风马面裙日常套装', 'exclude_modern_hanfu_adjacent'];
        yield '新中式盖过汉服' => ['新中式国风马面裙男款古风汉服男士套装', 'exclude_modern_hanfu_adjacent'];
        yield '晚清盖过马面裙' => ['晚清少奶奶重工马面裙汉服', 'exclude_qing_costume'];
        yield '汉服靴配件' => ['古装汉服靴子男女书生官靴', 'exclude_accessory_only'];
        yield 'cos关键词鞋靴' => ['古装靴COS动漫人物靴汉服鞋', 'exclude_performance_costume'];
        yield '裸cos' => ['汉服寄明月舞蹈衣服同款古装COS学生', 'exclude_performance_costume'];
        yield '空标题' => ['  ', 'no_explicit_hanfu_term'];
    }
}

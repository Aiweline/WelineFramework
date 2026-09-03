<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Service\Hanfu1688;

use PHPUnit\Framework\TestCase;
use Weline\Product\Service\Hanfu1688\HanfuProductClassifier;

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
        yield '汉服直接证据' => ['儿童汉服女童夏季超仙唐装'];
        yield '马面裙' => ['明制新中式马面裙现货'];
        yield '宋裤' => ['宋制汉服百迭裙宋裤套装'];
        yield '圆领袍' => ['明制圆领袍男秋冬款'];
    }

    /** @dataProvider rejectedTitles */
    public function testRejectsAdjacentCostumesWithoutExplicitHanfuEvidence(string $title): void
    {
        $decision = (new HanfuProductClassifier())->classify($title);

        self::assertFalse($decision['accepted']);
        self::assertSame('', $decision['matched_term']);
        self::assertSame('no_explicit_hanfu_term', $decision['reason']);
    }

    /** @return iterable<string,array{string}> */
    public static function rejectedTitles(): iterable
    {
        yield '校服' => ['幼儿园园服小学生校服书香中国风'];
        yield '武术服' => ['儿童武术服少儿表演服唐装'];
        yield '茶服' => ['中式禅意茶服夏季薄款'];
        yield '舞台服' => ['中国风大合唱舞台演出服'];
        yield '新中式' => ['新中式女装夏季上衣'];
        yield '空标题' => ['  '];
    }
}

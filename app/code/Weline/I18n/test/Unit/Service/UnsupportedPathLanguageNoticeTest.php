<?php

declare(strict_types=1);

namespace Weline\I18n\Test\Unit\Service;

use PHPUnit\Framework\TestCase;
use Weline\I18n\Service\UnsupportedPathLanguageNotice;

final class UnsupportedPathLanguageNoticeTest extends TestCase
{
    public function testReplaceLanguageSegmentKeepsTheRestOfThePath(): void
    {
        self::assertSame(
            '/zh_Hans_CN/blog/how-to-wear-mamian-skirt-steps',
            UnsupportedPathLanguageNotice::replaceLanguageSegment(
                '/ja_JP/blog/how-to-wear-mamian-skirt-steps',
                'ja_JP',
                'zh_Hans_CN'
            )
        );
        self::assertSame(
            '/USD/en_US/blog/post?keep=1',
            UnsupportedPathLanguageNotice::replaceLanguageSegment(
                '/USD/ja_JP/blog/post?keep=1',
                'ja_JP',
                'en_US'
            )
        );
    }
}

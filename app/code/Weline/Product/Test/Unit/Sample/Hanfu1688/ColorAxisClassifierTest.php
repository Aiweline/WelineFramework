<?php

declare(strict_types=1);

namespace Weline\Product\Test\Unit\Sample\Hanfu1688;

use PHPUnit\Framework\TestCase;
use Weline\Product\Sample\Hanfu1688\ColorAxisClassifier;

final class ColorAxisClassifierTest extends TestCase
{
    public function testClassifiesLookRefCharacterStyleAndRealColor(): void
    {
        self::assertSame(ColorAxisClassifier::AXIS_LOOK_REF, ColorAxisClassifier::classify('图一'));
        self::assertSame(ColorAxisClassifier::AXIS_LOOK_REF, ColorAxisClassifier::classify('图十六'));
        self::assertSame(ColorAxisClassifier::AXIS_CHARACTER, ColorAxisClassifier::classify('关羽'));
        self::assertSame(ColorAxisClassifier::AXIS_CHARACTER, ColorAxisClassifier::classify('曹操1'));
        self::assertSame(ColorAxisClassifier::AXIS_CHARACTER, ColorAxisClassifier::classify('红色 刘备'));
        self::assertSame(ColorAxisClassifier::AXIS_CHARACTER, ColorAxisClassifier::classify('诸葛亮 白鱼送红丝帽'));
        self::assertSame(
            ColorAxisClassifier::AXIS_STYLE_TYPE,
            ColorAxisClassifier::classify('粉色 短袖+送头饰+眉心贴+绣花鞋 24-40下单后备注'),
        );
        self::assertSame(
            ColorAxisClassifier::AXIS_STYLE_TYPE,
            ColorAxisClassifier::classify('女款白色长袖上衣＋红短裙505'),
        );
        self::assertSame(
            ColorAxisClassifier::AXIS_STYLE_TYPE,
            ColorAxisClassifier::classify('火焱白蓝直袖男女同款+帽子'),
        );
        self::assertSame(ColorAxisClassifier::AXIS_COLOR, ColorAxisClassifier::classify('墨黑色'));
        self::assertSame(ColorAxisClassifier::AXIS_COLOR, ColorAxisClassifier::classify('大红色'));
        self::assertSame(ColorAxisClassifier::AXIS_COLOR, ColorAxisClassifier::classify('米白色'));
        self::assertSame(ColorAxisClassifier::AXIS_STYLE_TYPE, ColorAxisClassifier::classify('女款红色'));
        self::assertSame(ColorAxisClassifier::AXIS_STYLE_TYPE, ColorAxisClassifier::classify('白色上衣'));
        self::assertSame(ColorAxisClassifier::AXIS_CHARACTER, ColorAxisClassifier::classify('帝王 黑色 送垂帘帽'));
        self::assertSame(ColorAxisClassifier::AXIS_CHARACTER, ColorAxisClassifier::classify('赵子龙 白侠+红披风'));
        self::assertSame(ColorAxisClassifier::AXIS_PROP, ColorAxisClassifier::classify('黑色鞋套（松紧性大均码）'));
        self::assertSame(ColorAxisClassifier::AXIS_PROP, ColorAxisClassifier::classify('黑色布鞋（请备注鞋子号码）'));
        self::assertSame(ColorAxisClassifier::AXIS_PROP, ColorAxisClassifier::classify('白色胡子（均码，大小自己修剪）'));
        self::assertSame(ColorAxisClassifier::AXIS_PROP, ColorAxisClassifier::classify('三字经竹简（大小是24*30cm）'));
        self::assertSame(ColorAxisClassifier::AXIS_PROP, ColorAxisClassifier::classify('一瓶胶水（粘胡须专用）'));
        self::assertSame(ColorAxisClassifier::AXIS_PROP, ColorAxisClassifier::classify('古书弟子规（A5尺寸14*20厘米）'));
        self::assertSame(ColorAxisClassifier::AXIS_LOOK_REF, ColorAxisClassifier::classify('如图'));
        self::assertSame(ColorAxisClassifier::AXIS_STYLE_TYPE, ColorAxisClassifier::classify('国学服（红色）'));
        self::assertSame(ColorAxisClassifier::AXIS_STYLE_TYPE, ColorAxisClassifier::classify('A款红色'));
        self::assertSame(ColorAxisClassifier::AXIS_STYLE_TYPE, ColorAxisClassifier::classify('白色宽袖衣'));
        self::assertContains(ColorAxisClassifier::AXIS_PROP, ColorAxisClassifier::targetAxes());
        self::assertSame('配件', ColorAxisClassifier::attributeName(ColorAxisClassifier::AXIS_PROP));
    }
}

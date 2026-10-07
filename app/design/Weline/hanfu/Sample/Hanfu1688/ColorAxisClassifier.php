<?php

declare(strict_types=1);

namespace Weline\Product\Sample\Hanfu1688;

/**
 * Classifies 1688「颜色分类」option labels that are not real colors
 * into dedicated variant axes.
 */
final class ColorAxisClassifier
{
    public const AXIS_COLOR = 'color';
    public const AXIS_CHARACTER = 'character';
    public const AXIS_LOOK_REF = 'look_ref';
    public const AXIS_STYLE_TYPE = 'style_type';
    /** Props / add-ons misfiled under 1688 color (shoes, beard, bamboo slips, books…). */
    public const AXIS_PROP = 'prop';

    private const CHARACTERS = '关羽|刘备|曹操|诸葛亮|孔明|赵云|赵子龙|子龙|张飞|吕布|孙尚香|貂蝉|黄忠|马超|魏延|周瑜|孙权|典韦|许褚|司马懿|陆逊|甘宁|姜维|邓艾|庞统|徐庶|鲁肃|黄盖|袁绍|董卓|小乔|大乔|虞姬|帝王|宦官|皇后|士兵|大臣|李白|员外';

    public static function classify(string $label): string
    {
        $label = trim((string)preg_replace('/\s+/u', ' ', $label));
        if ($label === '') {
            return self::AXIS_COLOR;
        }

        if ($label === '如图' || preg_match('/^图([一二三四五六七八九十百零〇0-9]+)$/u', $label) === 1) {
            return self::AXIS_LOOK_REF;
        }

        if (preg_match(
            '/鞋套|布鞋|胡子|胡须|竹简|胶水|古书|三字经竹简|弟子规竹简|论语竹简|唐诗三百首|粘胡须/u',
            $label,
        ) === 1) {
            return self::AXIS_PROP;
        }

        if (preg_match('/(?:' . self::CHARACTERS . ')/u', $label) === 1) {
            return self::AXIS_CHARACTER;
        }

        if (preg_match(
            '/[+＋]|套装|送头饰|送帽|下单|男女同款|马面裙|仅上衣|单拍|单件|里衣|大袖衫|广袖|直袖|绣花鞋'
            . '|(上衣.+(?:裙|裤))|(?:(?:裙|裤).+上衣)'
            . '|^(?:短袖|长袖)[+＋]|^(?:女款|男款)'
            . '|款式[一二]|锦鲤|马甲[+＋]|飞天款'
            . '|上衣|单裙子|大袖|短袖|长袖|女款|男款|上襦|桃花源|博学服|荷花|火炎|火焱|龙吟|玥鱼|雪竹|玉龙|锦花'
            . '|提花|圈花|小花|有位姑娘|白衣花边|衣红裙|衣黑裙|红衣|黑衣'
            . '|三件套|两件套|件套|全套|斗篷|外套|云肩|随意搭配|破\\d+米摆|米摆'
            . '|白裙|蓝裙|红裙|凤尾|清风|凌风|雀栖|腕纱|渐变|绣花|金边|繁花|龙凤|伞\\d|十七'
            . '|国学服|书童服|宽袖衣|广裙|青花瓷|包边|A款|B款|男红|男黑|女红|女黑/u',
            $label,
        ) === 1) {
            return self::AXIS_STYLE_TYPE;
        }

        return self::AXIS_COLOR;
    }

    public static function isMisfiledColor(string $label): bool
    {
        return self::classify($label) !== self::AXIS_COLOR;
    }

    /**
     * @return list<string>
     */
    public static function targetAxes(): array
    {
        return [
            self::AXIS_CHARACTER,
            self::AXIS_LOOK_REF,
            self::AXIS_STYLE_TYPE,
            self::AXIS_PROP,
        ];
    }

    public static function attributeName(string $code): string
    {
        return match ($code) {
            self::AXIS_COLOR => '颜色',
            self::AXIS_CHARACTER => '角色',
            self::AXIS_LOOK_REF => '图款',
            self::AXIS_STYLE_TYPE => '类型',
            self::AXIS_PROP => '配件',
            default => $code,
        };
    }
}

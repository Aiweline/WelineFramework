<?php

declare(strict_types=1);

namespace Weline\Product\Sample\Hanfu1688;

final class HanfuProductClassifier
{
    /** @var list<string> */
    private const EXPLICIT_TERMS = [
        '汉服',
        '马面裙',
        '齐胸襦裙',
        '齐腰襦裙',
        '交领襦裙',
        '坦领襦裙',
        '袄裙',
        '褙子',
        '曲裾',
        '直裾',
        '圆领袍',
        '飞鱼服',
        '百迭裙',
        '宋裤',
        '披袄',
        '诃子裙',
        '曳撒',
        '大袖衫',
        '晋襦',
    ];

    /**
     * Force-exclude evidence outranks any positive Hanfu term.
     *
     * @var list<array{0:string,1:string}>
     */
    private const FORCE_EXCLUDES = [
        ['exclude_qing_costume', '晚清|清朝|清宫|清代|清女|少奶奶|格格服|旗装|旗袍|宫女服|民国|民国风'],
        ['exclude_modern_hanfu_adjacent', '汉元素|新中式|现代改良|改良汉服|汉洋折衷|国潮改良|中式通勤|现代禅意'],
        ['exclude_performance_costume', 'cosplay|cos服|cos|影楼服|摄影服|舞台服|表演服|演出服|影视服|剧组服|角色扮演|古装写真'],
        ['exclude_custom_service', '来图定制|来样定制|加工定制|打版|代加工|贴牌加工|小单定制|汉服加工|服装加工|定金|补差价|专拍链接'],
        ['exclude_accessory_only', '发簪|假发|发包|头饰|耳饰|项链|腰带|宫绦|胸针|单独配饰|汉服靴|古装靴|汉鞋|官靴|布靴|汉服鞋|古装鞋'],
        ['exclude_non_hanfu_garment', '唐装|中山装|民族服装|禅茶服|瑜伽服|武术服|僧服|现代连衣裙|现代衬衫|现代西装'],
    ];

    /** @return array{accepted:bool,matched_term:string,reason:string} */
    public function classify(string $title): array
    {
        $title = trim(html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($title === '') {
            return [
                'accepted' => false,
                'matched_term' => '',
                'reason' => 'no_explicit_hanfu_term',
            ];
        }

        $normalized = mb_strtolower(preg_replace('/\s+/u', ' ', $title) ?? $title);

        foreach (self::FORCE_EXCLUDES as [$reason, $pattern]) {
            if (preg_match('/(?:' . $pattern . ')/u', $normalized) === 1) {
                return [
                    'accepted' => false,
                    'matched_term' => '',
                    'reason' => $reason,
                ];
            }
        }

        foreach (self::EXPLICIT_TERMS as $term) {
            if (str_contains($title, $term)) {
                return [
                    'accepted' => true,
                    'matched_term' => $term,
                    'reason' => 'explicit_hanfu_term',
                ];
            }
        }

        return [
            'accepted' => false,
            'matched_term' => '',
            'reason' => 'no_explicit_hanfu_term',
        ];
    }
}

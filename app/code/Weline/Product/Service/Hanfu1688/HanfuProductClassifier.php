<?php

declare(strict_types=1);

namespace Weline\Product\Service\Hanfu1688;

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
    ];

    /** @return array{accepted:bool,matched_term:string,reason:string} */
    public function classify(string $title): array
    {
        $title = trim(html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
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

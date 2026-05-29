<?php

declare(strict_types=1);

namespace WeShop\Product\Helper;

final class HanfuDemoOptionImageProvider
{
    private const COLORS = [
        'red' => [
            'option_id' => 900101,
            'label' => '红色',
            'origin_value' => 'Red',
            'swatch_value' => '#c2410c',
        ],
        'pink' => [
            'option_id' => 900102,
            'label' => '粉色',
            'origin_value' => 'Pink',
            'swatch_value' => '#ec4899',
        ],
        'green' => [
            'option_id' => 900103,
            'label' => '绿色',
            'origin_value' => 'Green',
            'swatch_value' => '#15803d',
        ],
        'navy' => [
            'option_id' => 900104,
            'label' => '黛青',
            'origin_value' => 'Navy',
            'swatch_value' => '#1e3a8a',
        ],
        'beige' => [
            'option_id' => 900105,
            'label' => '米杏',
            'origin_value' => 'Beige',
            'swatch_value' => '#d6c6a8',
        ],
        'white' => [
            'option_id' => 900106,
            'label' => '月白',
            'origin_value' => 'White',
            'swatch_value' => '#f8fafc',
        ],
        'blue' => [
            'option_id' => 900107,
            'label' => '天青',
            'origin_value' => 'Blue',
            'swatch_value' => '#38bdf8',
        ],
        'purple' => [
            'option_id' => 900108,
            'label' => '雪青',
            'origin_value' => 'Purple',
            'swatch_value' => '#a855f7',
        ],
        'gold' => [
            'option_id' => 900109,
            'label' => '金棕',
            'origin_value' => 'Gold',
            'swatch_value' => '#c99700',
        ],
        'brown' => [
            'option_id' => 900110,
            'label' => '栗棕',
            'origin_value' => 'Brown',
            'swatch_value' => '#92400e',
        ],
        'black' => [
            'option_id' => 900111,
            'label' => '墨黑',
            'origin_value' => 'Black',
            'swatch_value' => '#111827',
        ],
        'silver' => [
            'option_id' => 900112,
            'label' => '银灰',
            'origin_value' => 'Silver',
            'swatch_value' => '#c0c7d2',
        ],
        'natural' => [
            'option_id' => 900113,
            'label' => '原麻',
            'origin_value' => 'Natural',
            'swatch_value' => '#b08d57',
        ],
        'gray' => [
            'option_id' => 900114,
            'label' => '烟灰',
            'origin_value' => 'Gray',
            'swatch_value' => '#6b7280',
        ],
    ];

    private const SIZES = [
        'm' => ['option_id' => 900201, 'label' => 'M'],
        'l' => ['option_id' => 900202, 'label' => 'L'],
        'xl' => ['option_id' => 900203, 'label' => 'XL'],
    ];

    private const STYLES = [
        'classic' => [
            'option_id' => 900301,
            'label' => '经典款',
            'origin_value' => 'Classic',
        ],
        'lifestyle' => [
            'option_id' => 900302,
            'label' => '生活款',
            'origin_value' => 'Lifestyle',
        ],
        'detail' => [
            'option_id' => 900303,
            'label' => '细节款',
            'origin_value' => 'Detail',
        ],
    ];

    private const IMAGE_MATRIX = [
        'red' => [
            'classic' => 'https://images.pexels.com/photos/34521643/pexels-photo-34521643.jpeg?auto=compress&cs=tinysrgb&w=1200',
            'lifestyle' => 'https://images.pexels.com/photos/34521643/pexels-photo-34521643.jpeg?auto=compress&cs=tinysrgb&w=1200',
            'detail' => 'https://commons.wikimedia.org/wiki/Special:FilePath/Hanfuwithred.jpg?width=1200',
        ],
        'pink' => [
            'classic' => 'https://images.pexels.com/photos/30690994/pexels-photo-30690994.jpeg?auto=compress&cs=tinysrgb&w=1200',
            'lifestyle' => 'https://images.pexels.com/photos/30690993/pexels-photo-30690993.jpeg?auto=compress&cs=tinysrgb&w=1200',
            'detail' => 'https://images.pexels.com/photos/30690994/pexels-photo-30690994.jpeg?auto=compress&cs=tinysrgb&w=1200',
        ],
        'green' => [
            'classic' => 'https://images.pexels.com/photos/11740726/pexels-photo-11740726.jpeg?auto=compress&cs=tinysrgb&w=1200',
            'lifestyle' => 'https://images.pexels.com/photos/11740726/pexels-photo-11740726.jpeg?auto=compress&cs=tinysrgb&w=1200',
            'detail' => 'https://images.pexels.com/photos/11740726/pexels-photo-11740726.jpeg?auto=compress&cs=tinysrgb&w=1200',
        ],
        'navy' => [
            'classic' => 'https://images.pexels.com/photos/34757910/pexels-photo-34757910.jpeg?auto=compress&cs=tinysrgb&w=1200&demo=navy-classic',
            'lifestyle' => 'https://images.pexels.com/photos/34757910/pexels-photo-34757910.jpeg?auto=compress&cs=tinysrgb&w=1200&demo=navy-lifestyle',
            'detail' => 'https://images.pexels.com/photos/34757910/pexels-photo-34757910.jpeg?auto=compress&cs=tinysrgb&w=1200&demo=navy-detail',
        ],
        'beige' => [
            'classic' => 'https://images.pexels.com/photos/36679433/pexels-photo-36679433.jpeg?auto=compress&cs=tinysrgb&w=1200&demo=beige-classic',
            'lifestyle' => 'https://images.pexels.com/photos/36679433/pexels-photo-36679433.jpeg?auto=compress&cs=tinysrgb&w=1200&demo=beige-lifestyle',
            'detail' => 'https://images.pexels.com/photos/36679433/pexels-photo-36679433.jpeg?auto=compress&cs=tinysrgb&w=1200&demo=beige-detail',
        ],
        'white' => [
            'classic' => 'https://images.pexels.com/photos/8152155/pexels-photo-8152155.jpeg?auto=compress&cs=tinysrgb&w=1200&demo=white-classic',
            'lifestyle' => 'https://images.pexels.com/photos/8152155/pexels-photo-8152155.jpeg?auto=compress&cs=tinysrgb&w=1200&demo=white-lifestyle',
            'detail' => 'https://images.pexels.com/photos/8152155/pexels-photo-8152155.jpeg?auto=compress&cs=tinysrgb&w=1200&demo=white-detail',
        ],
        'blue' => [
            'classic' => 'https://images.pexels.com/photos/18077456/pexels-photo-18077456.jpeg?auto=compress&cs=tinysrgb&w=1200&demo=blue-classic',
            'lifestyle' => 'https://images.pexels.com/photos/18077456/pexels-photo-18077456.jpeg?auto=compress&cs=tinysrgb&w=1200&demo=blue-lifestyle',
            'detail' => 'https://images.pexels.com/photos/18077456/pexels-photo-18077456.jpeg?auto=compress&cs=tinysrgb&w=1200&demo=blue-detail',
        ],
        'purple' => [
            'classic' => 'https://images.pexels.com/photos/34521646/pexels-photo-34521646.jpeg?auto=compress&cs=tinysrgb&w=1200&demo=purple-classic',
            'lifestyle' => 'https://images.pexels.com/photos/34521646/pexels-photo-34521646.jpeg?auto=compress&cs=tinysrgb&w=1200&demo=purple-lifestyle',
            'detail' => 'https://images.pexels.com/photos/34521646/pexels-photo-34521646.jpeg?auto=compress&cs=tinysrgb&w=1200&demo=purple-detail',
        ],
        'gold' => [
            'classic' => 'https://images.pexels.com/photos/34521643/pexels-photo-34521643.jpeg?auto=compress&cs=tinysrgb&w=1200&demo=gold-classic',
            'lifestyle' => 'https://images.pexels.com/photos/34521643/pexels-photo-34521643.jpeg?auto=compress&cs=tinysrgb&w=1200&demo=gold-lifestyle',
            'detail' => 'https://images.pexels.com/photos/34521643/pexels-photo-34521643.jpeg?auto=compress&cs=tinysrgb&w=1200&demo=gold-detail',
        ],
        'brown' => [
            'classic' => 'https://images.pexels.com/photos/34521646/pexels-photo-34521646.jpeg?auto=compress&cs=tinysrgb&w=1200&demo=brown-classic',
            'lifestyle' => 'https://images.pexels.com/photos/34521646/pexels-photo-34521646.jpeg?auto=compress&cs=tinysrgb&w=1200&demo=brown-lifestyle',
            'detail' => 'https://images.pexels.com/photos/34521646/pexels-photo-34521646.jpeg?auto=compress&cs=tinysrgb&w=1200&demo=brown-detail',
        ],
        'black' => [
            'classic' => 'https://images.pexels.com/photos/34757910/pexels-photo-34757910.jpeg?auto=compress&cs=tinysrgb&w=1200&demo=black-classic',
            'lifestyle' => 'https://images.pexels.com/photos/34757910/pexels-photo-34757910.jpeg?auto=compress&cs=tinysrgb&w=1200&demo=black-lifestyle',
            'detail' => 'https://images.pexels.com/photos/34757910/pexels-photo-34757910.jpeg?auto=compress&cs=tinysrgb&w=1200&demo=black-detail',
        ],
        'silver' => [
            'classic' => 'https://images.pexels.com/photos/30690993/pexels-photo-30690993.jpeg?auto=compress&cs=tinysrgb&w=1200&demo=silver-classic',
            'lifestyle' => 'https://images.pexels.com/photos/30690993/pexels-photo-30690993.jpeg?auto=compress&cs=tinysrgb&w=1200&demo=silver-lifestyle',
            'detail' => 'https://images.pexels.com/photos/30690993/pexels-photo-30690993.jpeg?auto=compress&cs=tinysrgb&w=1200&demo=silver-detail',
        ],
        'natural' => [
            'classic' => 'https://images.pexels.com/photos/36679433/pexels-photo-36679433.jpeg?auto=compress&cs=tinysrgb&w=1200&demo=natural-classic',
            'lifestyle' => 'https://images.pexels.com/photos/36679433/pexels-photo-36679433.jpeg?auto=compress&cs=tinysrgb&w=1200&demo=natural-lifestyle',
            'detail' => 'https://images.pexels.com/photos/36679433/pexels-photo-36679433.jpeg?auto=compress&cs=tinysrgb&w=1200&demo=natural-detail',
        ],
        'gray' => [
            'classic' => 'https://images.pexels.com/photos/18077456/pexels-photo-18077456.jpeg?auto=compress&cs=tinysrgb&w=1200&demo=gray-classic',
            'lifestyle' => 'https://images.pexels.com/photos/18077456/pexels-photo-18077456.jpeg?auto=compress&cs=tinysrgb&w=1200&demo=gray-lifestyle',
            'detail' => 'https://images.pexels.com/photos/18077456/pexels-photo-18077456.jpeg?auto=compress&cs=tinysrgb&w=1200&demo=gray-detail',
        ],
    ];

    private function __construct()
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function colorOptions(int $productId): array
    {
        $options = [];
        foreach (self::COLORS as $code => $definition) {
            $image = self::imageFor($code, 'classic');
            $options[] = [
                'option_id' => $definition['option_id'],
                'code' => $code,
                'value' => self::localizedOptionLabel($definition),
                'origin_value' => $definition['origin_value'],
                'swatch_type' => 'color',
                'swatch_value' => $definition['swatch_value'],
                'option_image' => $image,
                'available_product_ids' => [$productId],
            ];
        }

        return $options;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function sizeOptions(int $productId): array
    {
        $options = [];
        foreach (self::SIZES as $code => $definition) {
            $options[] = [
                'option_id' => $definition['option_id'],
                'code' => $code,
                'value' => $definition['label'],
                'origin_value' => $definition['label'],
                'swatch_type' => 'text',
                'swatch_value' => $definition['label'],
                'available_product_ids' => [$productId],
            ];
        }

        return $options;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function styleOptions(int $productId, string $colorCode = 'red'): array
    {
        $options = [];
        foreach (self::STYLES as $code => $definition) {
            $image = self::imageFor($colorCode, $code);
            $options[] = [
                'option_id' => $definition['option_id'],
                'code' => $code,
                'value' => self::localizedOptionLabel($definition),
                'origin_value' => $definition['origin_value'],
                'swatch_type' => 'image',
                'swatch_value' => $image,
                'option_image' => $image,
                'available_product_ids' => [$productId],
            ];
        }

        return $options;
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function imageMatrix(): array
    {
        return self::IMAGE_MATRIX;
    }

    /**
     * @return array<int, string>
     */
    public static function allImages(): array
    {
        $images = [];
        foreach (self::IMAGE_MATRIX as $styleImages) {
            foreach ($styleImages as $image) {
                $images[] = $image;
            }
        }

        return \array_values(\array_unique($images));
    }

    public static function defaultImage(): string
    {
        return self::IMAGE_MATRIX['red']['classic'];
    }

    public static function imageFor(string $colorCode, string $styleCode): string
    {
        $colorCode = \strtolower(\trim($colorCode));
        $styleCode = \strtolower(\trim($styleCode));

        return self::IMAGE_MATRIX[$colorCode][$styleCode]
            ?? self::IMAGE_MATRIX[$colorCode]['classic']
            ?? self::defaultImage();
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function swatchMap(): array
    {
        $map = [];
        foreach (self::COLORS as $code => $definition) {
            $map[$code] = [
                'swatch_type' => 'color',
                'swatch_value' => $definition['swatch_value'],
                'option_image' => self::imageFor($code, 'classic'),
            ];
        }
        foreach (self::SIZES as $code => $definition) {
            $map[$code] = [
                'swatch_type' => 'text',
                'swatch_value' => $definition['label'],
            ];
        }
        foreach (self::STYLES as $code => $definition) {
            $image = self::imageFor('red', $code);
            $map[$code] = [
                'swatch_type' => 'image',
                'swatch_value' => $image,
                'option_image' => $image,
            ];
        }

        return $map;
    }

    /**
     * @return array<int, array<string, string>>
     */
    public static function swatchRows(): array
    {
        $rows = [];
        foreach (self::COLORS as $code => $definition) {
            $rows[(int) $definition['option_id']] = ['code' => $code] + self::swatchMap()[$code];
        }
        foreach (self::SIZES as $code => $definition) {
            $rows[(int) $definition['option_id']] = ['code' => $code] + self::swatchMap()[$code];
        }
        foreach (self::STYLES as $code => $definition) {
            $rows[(int) $definition['option_id']] = ['code' => $code] + self::swatchMap()[$code];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $definition
     */
    private static function localizedOptionLabel(array $definition): string
    {
        $sourceLabel = (string)($definition['label'] ?? '');
        $englishLabel = trim((string)($definition['origin_value'] ?? ''));
        if ($englishLabel !== '' && !self::usesChineseLocale()) {
            return $englishLabel;
        }

        return (string)__($sourceLabel !== '' ? $sourceLabel : $englishLabel);
    }

    private static function usesChineseLocale(): bool
    {
        try {
            $locale = (string)\Weline\Framework\App\State::getLangLocal();
        } catch (\Throwable) {
            $locale = '';
        }

        return str_starts_with(strtolower(trim($locale)), 'zh');
    }
}

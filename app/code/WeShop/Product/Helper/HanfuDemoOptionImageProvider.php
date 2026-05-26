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
                'value' => (string) __($definition['label']),
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
                'value' => (string) __($definition['label']),
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
}

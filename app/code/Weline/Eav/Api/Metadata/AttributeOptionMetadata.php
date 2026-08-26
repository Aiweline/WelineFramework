<?php

declare(strict_types=1);

namespace Weline\Eav\Api\Metadata;

/**
 * Immutable option metadata exposed by Eav.
 */
final readonly class AttributeOptionMetadata
{
    public function __construct(
        public int $id,
        public string $value,
        public string $code,
        public string $label,
        public int $sortOrder,
        public string $swatchImage = '',
        public string $swatchColor = '',
        public string $swatchText = '',
    ) {
    }

    /**
     * @return array<string, int|string>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'value' => $this->value,
            'code' => $this->code,
            'label' => $this->label,
            'sort_order' => $this->sortOrder,
            'swatch_image' => $this->swatchImage,
            'swatch_color' => $this->swatchColor,
            'swatch_text' => $this->swatchText,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Weline\Theme\Dto;

class ThemeSlotDefinition
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $area,
        public readonly array $accept = [],
        public readonly bool $exclusive = false,
        public readonly bool $multiple = true,
        public readonly bool $append = false,
        public readonly bool $prepend = false,
        public readonly array $meta = [],
        public readonly ?string $sourcePath = null,
    ) {
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'area' => $this->area,
            'accept' => $this->accept,
            'exclusive' => $this->exclusive,
            'multiple' => $this->multiple,
            'append' => $this->append,
            'prepend' => $this->prepend,
            'source_path' => $this->sourcePath,
            'meta' => $this->meta,
        ];
    }
}

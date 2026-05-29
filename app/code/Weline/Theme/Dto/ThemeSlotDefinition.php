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
        $position = $this->meta['position'] ?? null;

        return [
            'id' => $this->id,
            'name' => $this->name,
            'area' => $this->area,
            'accept' => $this->accept,
            'reject' => $this->meta['reject'] ?? [],
            'exclusive' => $this->exclusive,
            'multiple' => $this->multiple,
            'append' => $this->append,
            'prepend' => $this->prepend,
            'position' => is_string($position) ? $position : null,
            'max' => $this->meta['max'] ?? null,
            'min' => $this->meta['min'] ?? null,
            'required' => (bool)($this->meta['required'] ?? false),
            'source_path' => $this->sourcePath,
            'meta' => $this->meta,
        ];
    }
}

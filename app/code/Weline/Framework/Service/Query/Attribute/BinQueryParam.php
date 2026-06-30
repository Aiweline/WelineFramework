<?php
declare(strict_types=1);

namespace Weline\Framework\Service\Query\Attribute;

#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class BinQueryParam
{
    public function __construct(
        public readonly string $name,
        public readonly string $type = 'mixed',
        public readonly bool $required = false,
        public readonly mixed $default = null,
        public readonly string $description = '',
        public readonly bool $nullable = false,
        public readonly bool $cacheKey = false,
        public readonly mixed $min = null,
        public readonly mixed $max = null,
        public readonly int $maxLength = 0,
        public readonly int $maxItems = 0
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toDescriptor(): array
    {
        $descriptor = [
            'name' => $this->name,
            'type' => $this->type,
            'required' => $this->required,
            'description' => $this->description,
        ];

        if ($this->default !== null) {
            $descriptor['default'] = $this->default;
        }
        if ($this->nullable) {
            $descriptor['nullable'] = true;
        }
        if ($this->cacheKey) {
            $descriptor['cache_key'] = true;
        }
        if ($this->min !== null) {
            $descriptor['min'] = $this->min;
        }
        if ($this->max !== null) {
            $descriptor['max'] = $this->max;
        }
        if ($this->maxLength > 0) {
            $descriptor['max_length'] = $this->maxLength;
        }
        if ($this->maxItems > 0) {
            $descriptor['max_items'] = $this->maxItems;
        }

        return $descriptor;
    }
}

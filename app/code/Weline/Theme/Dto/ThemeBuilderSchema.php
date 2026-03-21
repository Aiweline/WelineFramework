<?php

declare(strict_types=1);

namespace Weline\Theme\Dto;

class ThemeBuilderSchema
{
    public function __construct(
        public readonly int $themeId,
        public readonly string $themeName,
        public readonly string $area,
        public readonly string $fingerprint,
        public readonly array $layouts = [],
        public readonly array $partials = [],
        public readonly array $components = [],
        public readonly array $variables = [],
        public readonly array $colors = [],
        public readonly array $slots = [],
        public readonly array $meta = [],
    ) {
    }

    public function toArray(): array
    {
        return [
            'theme_id' => $this->themeId,
            'theme_name' => $this->themeName,
            'area' => $this->area,
            'fingerprint' => $this->fingerprint,
            'layouts' => $this->layouts,
            'partials' => $this->partials,
            'components' => $this->components,
            'variables' => $this->variables,
            'colors' => $this->colors,
            'slots' => $this->slots,
            'meta' => $this->meta,
        ];
    }
}

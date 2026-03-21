<?php

declare(strict_types=1);

namespace Weline\Theme\Interface;

use Weline\Theme\Dto\ThemeComponentDefinition;
use Weline\Theme\Model\WelineTheme;

interface ThemeComponentSourceInterface
{
    public function getName(): string;

    /**
     * @return ThemeComponentDefinition[]
     */
    public function collect(string $area, ?WelineTheme $theme = null, array $options = []): array;
}

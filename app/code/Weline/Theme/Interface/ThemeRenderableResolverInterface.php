<?php

declare(strict_types=1);

namespace Weline\Theme\Interface;

use Weline\Theme\Dto\ThemeComponentDefinition;
use Weline\Theme\Dto\ThemeRenderable;

interface ThemeRenderableResolverInterface
{
    public function resolve(ThemeComponentDefinition $definition, array $config = []): ThemeRenderable;
}

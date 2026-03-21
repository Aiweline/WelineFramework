<?php

declare(strict_types=1);

namespace Weline\Theme\Service;

use Weline\Theme\Dto\ThemeComponentDefinition;
use Weline\Theme\Dto\ThemeRenderable;
use Weline\Theme\Interface\ThemeRenderableResolverInterface;

class ThemeRenderableResolver implements ThemeRenderableResolverInterface
{
    public function resolve(ThemeComponentDefinition $definition, array $config = []): ThemeRenderable
    {
        if ($definition->templateContent !== null && $definition->renderMode === ThemeRenderable::MODE_TEMPLATE_CONTENT) {
            return new ThemeRenderable(
                ThemeRenderable::MODE_TEMPLATE_CONTENT,
                templateContent: $definition->templateContent,
            );
        }

        if ($definition->blockClass !== null && $definition->blockClass !== '' && $definition->renderMode === ThemeRenderable::MODE_BLOCK_CLASS) {
            return new ThemeRenderable(
                ThemeRenderable::MODE_BLOCK_CLASS,
                blockClass: $definition->blockClass,
            );
        }

        return new ThemeRenderable(
            ThemeRenderable::MODE_TEMPLATE_PATH,
            templatePath: $definition->templatePath,
        );
    }
}

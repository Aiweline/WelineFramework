<?php

declare(strict_types=1);

namespace Weline\Widget\Api\Param;

/** Public, data-only boundary for rendering a widget parameter form. */
interface ParamFormRendererInterface
{
    /**
     * @param array<string, array<string, mixed>> $params
     * @param array<string, mixed> $config
     * @param array<string, mixed> $options
     */
    public function renderForm(
        int|string $layoutId,
        array $params,
        array $config = [],
        array $options = [],
    ): string;
}

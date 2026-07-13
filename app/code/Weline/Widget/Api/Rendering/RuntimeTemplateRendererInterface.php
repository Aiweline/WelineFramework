<?php

declare(strict_types=1);

namespace Weline\Widget\Api\Rendering;

/** Public rendering boundary; callers exchange only template text and scalar dictionaries. */
interface RuntimeTemplateRendererInterface
{
    /** @param array<string, mixed> $dictionary */
    public function renderContent(string $templateContent, array $dictionary = []): string;
}

<?php

declare(strict_types=1);

namespace Weline\Framework\View\Head;

/**
 * Adds module-owned data to the generic page-head render context.
 */
interface HeadContextProviderInterface
{
    /**
     * @param mixed $template
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function provide($template, array $context): array;
}

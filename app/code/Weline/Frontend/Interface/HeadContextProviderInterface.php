<?php

declare(strict_types=1);

namespace Weline\Frontend\Interface;

/**
 * @deprecated Implement \Weline\Framework\View\Head\HeadContextProviderInterface.
 */
interface HeadContextProviderInterface extends \Weline\Framework\View\Head\HeadContextProviderInterface
{
    /**
     * @param mixed $template
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function provide($template, array $context): array;
}

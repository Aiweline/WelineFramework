<?php

declare(strict_types=1);

namespace Weline\Frontend\Interface;

interface HeadContextProviderInterface
{
    /**
     * @param mixed $template
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function provide($template, array $context): array;
}

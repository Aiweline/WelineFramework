<?php

declare(strict_types=1);

namespace Weline\Frontend\Interface;

interface HeadPolicyProviderInterface
{
    /**
     * @param mixed $template
     * @param array<string, mixed> $policy
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function provide($template, array $policy, array $context): array;
}

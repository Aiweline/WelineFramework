<?php

declare(strict_types=1);

namespace Weline\Seo\Structure;

interface SeoStructureNodeBuilderInterface
{
    /**
     * @param array<string, mixed> $context
     */
    public function supports(array $context): bool;

    /**
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    public function buildNodes(array $context, string $url): array;
}

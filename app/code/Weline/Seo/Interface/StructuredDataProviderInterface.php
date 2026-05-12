<?php

declare(strict_types=1);

namespace Weline\Seo\Interface;

interface StructuredDataProviderInterface
{
    /**
     * @param mixed $template
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    public function provideStructuredData($template, array $context): array;
}

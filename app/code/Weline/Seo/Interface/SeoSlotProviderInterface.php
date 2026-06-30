<?php

declare(strict_types=1);

namespace Weline\Seo\Interface;

interface SeoSlotProviderInterface
{
    /**
     * @param mixed $template
     * @param array<string, mixed> $context
     * @param array<string, mixed> $options
     */
    public function supports(string $slot, $template, array $context, array $options = []): bool;

    /**
     * Return structured SEO slot payload. Providers should not return final HTML.
     *
     * @param mixed $template
     * @param array<string, mixed> $context
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function provide(string $slot, $template, array $context, array $options = []): array;
}

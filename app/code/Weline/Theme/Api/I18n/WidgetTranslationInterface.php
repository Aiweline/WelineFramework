<?php

declare(strict_types=1);

namespace Weline\Theme\Api\I18n;

/**
 * Public boundary for storefront widget/page copy resolved against the active theme.
 *
 * Consumers must not depend on Theme\Helper\WidgetI18n directly; the helper stays
 * an implementation detail behind this contract.
 */
interface WidgetTranslationInterface
{
    /**
     * @param list<scalar|null> $args
     */
    public function label(string $configuredTitle, string $defaultSource = '', array $args = []): string;
}

<?php

declare(strict_types=1);

namespace Weline\Theme\Service\I18n;

use Weline\Theme\Api\I18n\WidgetTranslationInterface;
use Weline\Theme\Helper\WidgetI18n;

/**
 * Adapter that exposes the legacy static WidgetI18n helper through the public contract.
 */
final class WidgetTranslation implements WidgetTranslationInterface
{
    public function label(string $configuredTitle, string $defaultSource = '', array $args = []): string
    {
        return WidgetI18n::label($configuredTitle, $defaultSource, $args);
    }
}

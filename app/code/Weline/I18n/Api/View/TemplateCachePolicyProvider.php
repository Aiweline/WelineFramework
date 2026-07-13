<?php

declare(strict_types=1);

namespace Weline\I18n\Api\View;

use Weline\Framework\View\Cache\TemplateCachePolicyProviderInterface;

final class TemplateCachePolicyProvider implements TemplateCachePolicyProviderInterface
{
    public function policies(): array
    {
        return [
            'aggregate_hooks' => [
                'header-language-switcher' => [
                    'context' => 'i18n',
                    'event' => 'Weline_I18n::header-language-switcher-data',
                    'scope' => 'language',
                ],
                'header-currency-switcher' => [
                    'context' => 'i18n',
                    'event' => 'Weline_I18n::header-currency-switcher-data',
                    'scope' => 'currency',
                ],
            ],
            'output_files' => [
                'Weline_I18n::hooks/header-language-switcher.phtml' => [
                    'context' => 'i18n',
                    'event' => 'Weline_I18n::header-language-switcher-data',
                    'scope' => 'language',
                ],
                'Weline_I18n::hooks/header-currency-switcher.phtml' => [
                    'context' => 'i18n',
                    'event' => 'Weline_I18n::header-currency-switcher-data',
                    'scope' => 'currency',
                ],
            ],
        ];
    }
}

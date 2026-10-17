<?php

declare(strict_types=1);

namespace Weline\I18n\Api\View;

use Weline\Framework\View\Cache\TemplateCachePolicyProviderInterface;

final class TemplateCachePolicyProvider implements TemplateCachePolicyProviderInterface
{
    public function policies(): array
    {
        // Switcher Hook HTML contains instance-local DOM ids — do not register
        // language/currency switcher fragments here.
        // body-end inline-edit shell is guest/operator-sharded via body_end context.
        return [
            'output_files' => [
                'Weline_I18n::hooks/Weline_Theme/frontend/layouts/base/body-end.phtml' => ['context' => 'body_end'],
            ],
        ];
    }
}

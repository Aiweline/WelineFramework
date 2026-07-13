<?php

declare(strict_types=1);

namespace Weline\Theme\Api\View;

use Weline\Framework\View\Cache\TemplateCachePolicyProviderInterface;

final class TemplateCachePolicyProvider implements TemplateCachePolicyProviderInterface
{
    public function policies(): array
    {
        return [
            'request_hooks' => [
                'header-account-links',
            ],
            'diagnostic_hooks' => [
                'account.sidebar',
                'account.sidebar.content',
            ],
            'aggregate_hooks' => [
                'account.sidebar' => ['context' => 'account_sidebar'],
                'header-account-links' => ['context' => 'frontend_auth'],
                'header-orders' => ['context' => 'header_action'],
                'Weline_Theme::frontend::layouts::base::body-end' => ['context' => 'body_end'],
            ],
        ];
    }
}

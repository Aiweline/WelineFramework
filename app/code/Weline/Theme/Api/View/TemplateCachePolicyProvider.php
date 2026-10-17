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
                'header-account',
                'header-account-links',
            ],
            'diagnostic_hooks' => [
                'account.sidebar',
                'account.sidebar.content',
            ],
            'aggregate_hooks' => [
                'account.sidebar' => ['context' => 'account_sidebar'],
                // Dual-rendered guest/signed-in markup; identical for every shopper.
                'header-account' => ['context' => 'static'],
                'header-account-links' => ['context' => 'static'],
                'header-orders' => ['context' => 'header_action'],
                // Empty compatibility stubs share one bag (context=body_end).
                // Guest-neutral Compare/Consent/B2B/Geo live in footer storefront-tail-chrome;
                // session/path-sensitive I18n/Visitor live on body-end-dynamic (no aggregate).
                'Weline_Theme::frontend::layouts::base::body-end' => [
                    'context' => 'body_end',
                    'require_all_outputs_cacheable' => true,
                ],
            ],
            'output_files' => [
                'Weline_Theme::hooks/Weline_Theme/frontend/layouts/base/body-end.phtml' => [
                    'context' => 'body_end',
                ],
            ],
        ];
    }
}

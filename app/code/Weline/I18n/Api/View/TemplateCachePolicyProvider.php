<?php

declare(strict_types=1);

namespace Weline\I18n\Api\View;

use Weline\Framework\View\Cache\TemplateCachePolicyProviderInterface;

final class TemplateCachePolicyProvider implements TemplateCachePolicyProviderInterface
{
    public function policies(): array
    {
        // Switcher Hook HTML contains instance-local DOM ids. Reusing a rendered
        // fragment in header, sidebar, and footer would duplicate those ids.
        return [];
    }
}

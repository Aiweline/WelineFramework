<?php

declare(strict_types=1);

namespace Weline\Theme\Extends\Module\Weline_Framework\Security\Csp;

use Weline\Framework\Http\Security\CspSourceContribution;
use Weline\Framework\Http\Security\CspSourceContributionProviderInterface;

/**
 * Theme editor canvas may embed a Host-only website (sibling *.test.weline.com)
 * from the admin project Host. Without these frame-src entries the iframe is blank.
 *
 * Zero-arg constructor required by CspSourceContributionRegistry (Extends).
 */
final class ThemeEditorSiblingFrameCsp implements CspSourceContributionProviderInterface
{
    public function contribution(): CspSourceContribution
    {
        return new CspSourceContribution([
            'frame-src' => [
                'https://*.test.weline.com',
                'http://*.test.weline.com',
                'https://*.weline.test',
                'http://*.weline.test',
            ],
        ]);
    }
}

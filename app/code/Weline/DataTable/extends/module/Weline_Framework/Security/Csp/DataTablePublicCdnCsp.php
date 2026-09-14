<?php

declare(strict_types=1);

namespace Weline\DataTable\Extends\Module\Weline_Framework\Security\Csp;

use Weline\Framework\Http\Security\CspSourceContribution;
use Weline\Framework\Http\Security\CspSourceContributionProviderInterface;

/**
 * Public CDN hosts used by DataTable demos / optional Bootstrap+FA examples.
 *
 * Zero-arg constructor required by CspSourceContributionRegistry (Extends).
 */
final class DataTablePublicCdnCsp implements CspSourceContributionProviderInterface
{
    public function contribution(): CspSourceContribution
    {
        return new CspSourceContribution([
            'script-src' => [
                'https://ajax.googleapis.com',
                'https://cdn.jsdelivr.net',
                'https://cdnjs.cloudflare.com',
                'https://unpkg.com',
            ],
            'style-src' => [
                'https://cdn.jsdelivr.net',
                'https://cdnjs.cloudflare.com',
                'https://fonts.googleapis.com',
                'https://fonts.gstatic.com',
            ],
            'connect-src' => [
                'https://cdn.jsdelivr.net',
                'https://cdnjs.cloudflare.com',
                'https://unpkg.com',
            ],
            'font-src' => [
                'https://cdnjs.cloudflare.com',
                'https://fonts.gstatic.com',
            ],
        ]);
    }
}

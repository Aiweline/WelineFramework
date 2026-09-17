<?php

declare(strict_types=1);

namespace Weline\Theme\Extends\Module\Weline_Framework\Security\Csp;

use Weline\Framework\Http\Security\CspSourceContribution;
use Weline\Framework\Http\Security\CspSourceContributionProviderInterface;

/**
 * Theme video-player widget embed hosts (YouTube + Vimeo).
 *
 * Zero-arg constructor required by CspSourceContributionRegistry (Extends).
 */
final class ThemeVideoEmbedCsp implements CspSourceContributionProviderInterface
{
    public function contribution(): CspSourceContribution
    {
        return new CspSourceContribution([
            'frame-src' => [
                'https://www.youtube.com',
                'https://www.youtube-nocookie.com',
                'https://player.vimeo.com',
            ],
            'img-src' => [
                'https://i.ytimg.com',
                'https://img.youtube.com',
                'https://i.vimeocdn.com',
            ],
            'connect-src' => [
                'https://www.youtube.com',
                'https://player.vimeo.com',
            ],
        ]);
    }
}

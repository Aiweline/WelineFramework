<?php

declare(strict_types=1);

namespace Weline\Theme\Extends\Module\Weline_Framework\Security\Csp;

use Weline\Framework\Http\Security\CspSourceContribution;
use Weline\Framework\Http\Security\CspSourceContributionProviderInterface;

/**
 * Theme video widget embed hosts (YouTube + Vimeo + Bilibili).
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
                'https://player.bilibili.com',
            ],
            'img-src' => [
                'https://i.ytimg.com',
                'https://img.youtube.com',
                'https://i.vimeocdn.com',
                'https://i0.hdslb.com',
                'https://i1.hdslb.com',
                'https://i2.hdslb.com',
            ],
            'connect-src' => [
                'https://www.youtube.com',
                'https://player.vimeo.com',
                'https://player.bilibili.com',
            ],
        ]);
    }
}

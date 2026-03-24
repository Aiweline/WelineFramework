<?php

declare(strict_types=1);

namespace WeShop\Analytics\Service;

use WeShop\Analytics\Interface\PixelProviderInterface;
use WeShop\Analytics\Provider\FacebookPixel;
use WeShop\Analytics\Provider\GoogleAnalytics;

class AnalyticsSnippetService
{
    protected ?PixelProviderInterface $googleAnalytics = null;
    protected ?PixelProviderInterface $facebookPixel = null;

    public function __construct(
        ?GoogleAnalytics $googleAnalytics = null,
        ?FacebookPixel $facebookPixel = null
    ) {
        $this->googleAnalytics = $googleAnalytics;
        $this->facebookPixel = $facebookPixel;
    }

    /**
     * @return array<int, array{provider:string,snippet:string}>
     */
    public function getFrontendPixelSnippets(): array
    {
        $snippets = [];

        foreach ($this->getProviders() as $providerCode => $provider) {
            if (!$provider instanceof PixelProviderInterface || !$provider->isEnabled()) {
                continue;
            }

            $snippet = trim($provider->getPixelCode());
            if ($snippet === '') {
                continue;
            }

            $snippets[] = [
                'provider' => $providerCode,
                'snippet' => $snippet,
            ];
        }

        return $snippets;
    }

    /**
     * @return array<string, PixelProviderInterface|null>
     */
    protected function getProviders(): array
    {
        return [
            'google' => $this->googleAnalytics,
            'facebook' => $this->facebookPixel,
        ];
    }
}

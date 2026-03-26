<?php

declare(strict_types=1);

namespace WeShop\Analytics\Service;

use WeShop\Analytics\Interface\PixelProviderInterface;
use WeShop\Analytics\Provider\BingAds;
use WeShop\Analytics\Provider\FacebookPixel;
use WeShop\Analytics\Provider\GoogleAnalytics;
use WeShop\Analytics\Provider\TikTokPixel;
use Weline\Framework\Runtime\RequestLifecycleTrace;

class AnalyticsSnippetService
{
    public const SLOT_HEAD = 'head';
    public const SLOT_BODY = 'body';
    public const SLOT_FOOTER = 'footer';

    protected ?PixelProviderInterface $googleAnalytics = null;
    protected ?PixelProviderInterface $facebookPixel = null;
    protected ?PixelProviderInterface $tiktokPixel = null;
    protected ?PixelProviderInterface $bingAds = null;

    public function __construct(
        ?GoogleAnalytics $googleAnalytics = null,
        ?FacebookPixel $facebookPixel = null,
        ?TikTokPixel $tiktokPixel = null,
        ?BingAds $bingAds = null
    ) {
        $this->googleAnalytics = $googleAnalytics;
        $this->facebookPixel = $facebookPixel;
        $this->tiktokPixel = $tiktokPixel;
        $this->bingAds = $bingAds;
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
     * @return array<int, array{provider:string,snippet:string}>
     */
    public function getFrontendPixelSnippetsBySlot(string $slot): array
    {
        $slot = $this->normalizeSlot($slot);
        if ($slot === null) {
            return [];
        }

        $traceEnabled = RequestLifecycleTrace::isEnabled();
        $traceName = 'analytics::AnalyticsSnippetService::getFrontendPixelSnippetsBySlot::' . $slot;
        $traceStart = $this->tracePush($traceEnabled, $traceName);
        $snippets = [];
        try {
            foreach ($this->getProviders() as $providerCode => $provider) {
                $providerTraceName = 'analytics::AnalyticsSnippetService::provider::' . $providerCode;
                $providerTraceStart = $this->tracePush($traceEnabled, $providerTraceName);
                try {
                    if (!$provider instanceof PixelProviderInterface) {
                        continue;
                    }

                    $enabledTraceName = $providerTraceName . '::isEnabled';
                    $enabledTraceStart = $this->tracePush($traceEnabled, $enabledTraceName);
                    try {
                        $isEnabled = $provider->isEnabled();
                    } finally {
                        $this->tracePop($traceEnabled, $enabledTraceName, $enabledTraceStart);
                    }

                    if (!$isEnabled) {
                        continue;
                    }

                    $resolveTraceName = $providerTraceName . '::resolveSlotSnippets';
                    $resolveTraceStart = $this->tracePush($traceEnabled, $resolveTraceName);
                    try {
                        $providerSnippets = $this->resolveProviderSlotSnippets($provider);
                    } finally {
                        $this->tracePop($traceEnabled, $resolveTraceName, $resolveTraceStart);
                    }

                    $snippet = trim((string) ($providerSnippets[$slot] ?? ''));
                    if ($snippet === '') {
                        continue;
                    }

                    $snippets[] = [
                        'provider' => $providerCode,
                        'snippet' => $snippet,
                    ];
                } finally {
                    $this->tracePop($traceEnabled, $providerTraceName, $providerTraceStart);
                }
            }

            return $snippets;
        } finally {
            $this->tracePop($traceEnabled, $traceName, $traceStart);
        }
    }

    /**
     * @return array<string, PixelProviderInterface|null>
     */
    protected function getProviders(): array
    {
        return [
            'google' => $this->googleAnalytics,
            'facebook' => $this->facebookPixel,
            'tiktok' => $this->tiktokPixel,
            'bing' => $this->bingAds,
        ];
    }

    /**
     * @return array{head:string,body:string,footer:string}
     */
    private function resolveProviderSlotSnippets(PixelProviderInterface $provider): array
    {
        if (method_exists($provider, 'getPixelHookSnippets')) {
            $snippets = $provider->getPixelHookSnippets();
            if (is_array($snippets)) {
                return [
                    self::SLOT_HEAD => trim((string) ($snippets[self::SLOT_HEAD] ?? '')),
                    self::SLOT_BODY => trim((string) ($snippets[self::SLOT_BODY] ?? '')),
                    self::SLOT_FOOTER => trim((string) ($snippets[self::SLOT_FOOTER] ?? '')),
                ];
            }
        }

        return $this->splitLegacyPixelSnippet($provider->getPixelCode());
    }

    /**
     * @return array{head:string,body:string,footer:string}
     */
    private function splitLegacyPixelSnippet(string $snippet): array
    {
        $snippet = trim($snippet);
        if ($snippet === '') {
            return [
                self::SLOT_HEAD => '',
                self::SLOT_BODY => '',
                self::SLOT_FOOTER => '',
            ];
        }

        $bodySnippets = [];
        if (preg_match_all('/<noscript\b[^>]*>[\s\S]*?<\/noscript>/i', $snippet, $matches)) {
            $bodySnippets = $matches[0] ?? [];
        }

        $body = trim(implode("\n", array_map('trim', array_filter($bodySnippets, 'is_string'))));
        $head = preg_replace('/<noscript\b[^>]*>[\s\S]*?<\/noscript>/i', '', $snippet);

        return [
            self::SLOT_HEAD => trim((string) $head),
            self::SLOT_BODY => $body,
            self::SLOT_FOOTER => '',
        ];
    }

    private function normalizeSlot(string $slot): ?string
    {
        $slot = strtolower(trim($slot));

        return match ($slot) {
            self::SLOT_HEAD,
            self::SLOT_BODY,
            self::SLOT_FOOTER => $slot,
            default => null,
        };
    }

    private function tracePush(bool $traceEnabled, string $name): float
    {
        if (!$traceEnabled) {
            return 0.0;
        }

        RequestLifecycleTrace::pushCurrentParent($name);

        return microtime(true);
    }

    private function tracePop(bool $traceEnabled, string $name, float $start, string $category = 'analytics'): void
    {
        if (!$traceEnabled) {
            return;
        }

        RequestLifecycleTrace::popCurrentParent();
        RequestLifecycleTrace::recordSpan(
            $name,
            (microtime(true) - $start) * 1000,
            $category
        );
    }
}

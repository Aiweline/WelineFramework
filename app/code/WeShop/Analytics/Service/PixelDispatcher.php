<?php

declare(strict_types=1);

namespace WeShop\Analytics\Service;

use WeShop\Analytics\Interface\PixelProviderInterface;
use WeShop\Analytics\Provider\FacebookPixel;
use WeShop\Analytics\Provider\GoogleAnalytics;

class PixelDispatcher
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
     * @param array<string, mixed> $eventData
     */
    public function track(string $eventName, array $eventData): void
    {
        $this->dispatch($eventName, $eventData);
    }

    /**
     * @param array<string, mixed> $eventData
     */
    public function dispatch(string $eventName, array $eventData): void
    {
        $eventName = $this->normalizeEventName($eventName);

        foreach ($this->getActiveProviders() as $provider) {
            try {
                $provider->sendEvent($eventName, $eventData);
            } catch (\Throwable $e) {
                w_log_warning('Pixel event dispatch failed: ' . $e->getMessage(), [
                    'event' => $eventName,
                    'provider' => $provider::class,
                ], 'pixel_dispatcher.log');
            }
        }
    }

    /**
     * @return array<int, PixelProviderInterface>
     */
    protected function getActiveProviders(): array
    {
        $providers = [];

        foreach ([$this->googleAnalytics, $this->facebookPixel] as $provider) {
            if (!$provider instanceof PixelProviderInterface || !$provider->isEnabled()) {
                continue;
            }

            $providers[] = $provider;
        }

        return $providers;
    }

    private function normalizeEventName(string $eventName): string
    {
        $eventName = trim($eventName);
        if ($eventName === '') {
            return 'page_view';
        }

        $eventName = preg_replace('/(?<!^)[A-Z]/', '_$0', $eventName) ?? $eventName;
        $eventName = str_replace(['-', ' '], '_', $eventName);
        $eventName = strtolower($eventName);

        return preg_replace('/_+/', '_', $eventName) ?? $eventName;
    }
}

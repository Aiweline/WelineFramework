<?php

declare(strict_types=1);

namespace WeShop\Analytics\Service;

use WeShop\Analytics\Interface\PixelProviderInterface;
use Weline\Framework\Manager\ObjectManager;

class PixelDispatcher
{
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

        foreach ($this->getProviderClasses() as $providerClass) {
            try {
                $provider = ObjectManager::getInstance($providerClass);
                if ($provider instanceof PixelProviderInterface && $provider->isEnabled()) {
                    $providers[] = $provider;
                }
            } catch (\Throwable) {
            }
        }

        return $providers;
    }

    /**
     * @return array<int, class-string<PixelProviderInterface>>
     */
    protected function getProviderClasses(): array
    {
        return [
            \WeShop\Analytics\Provider\FacebookPixel::class,
            \WeShop\Analytics\Provider\GoogleAnalytics::class,
        ];
    }
}

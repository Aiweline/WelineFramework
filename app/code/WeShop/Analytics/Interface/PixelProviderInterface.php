<?php

declare(strict_types=1);

namespace WeShop\Analytics\Interface;

interface PixelProviderInterface
{
    public function isEnabled(): bool;

    /**
     * @param array<string, mixed> $eventData
     */
    public function sendEvent(string $eventName, array $eventData): bool;

    public function getPixelCode(): string;
}

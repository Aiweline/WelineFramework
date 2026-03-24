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
        $eventData = $this->normalizeEventData($eventName, $eventData);

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

    /**
     * @param array<string, mixed> $eventData
     * @return array<string, mixed>
     */
    private function normalizeEventData(string $eventName, array $eventData): array
    {
        $eventData = $this->promoteAdditionalData($eventData);

        if (!isset($eventData['event_source_url']) && !empty($eventData['url'])) {
            $eventData['event_source_url'] = (string) $eventData['url'];
        }

        if (!isset($eventData['transaction_id'])) {
            $transactionId = $this->detectTransactionId($eventData);
            if ($transactionId !== null) {
                $eventData['transaction_id'] = $transactionId;
            }
        }

        if (!array_key_exists('value', $eventData)) {
            $value = $this->detectValue($eventData);
            if ($value !== null) {
                $eventData['value'] = $value;
            }
        }

        $items = $this->normalizeItems($eventData);
        if ($items !== []) {
            $eventData['items'] = $items;
        }

        if ($eventName === 'view_item' && !isset($eventData['content_name']) && !empty($eventData['name'])) {
            $eventData['content_name'] = (string) $eventData['name'];
        }

        return $eventData;
    }

    /**
     * @param array<string, mixed> $eventData
     * @return array<string, mixed>
     */
    private function promoteAdditionalData(array $eventData): array
    {
        $additional = $eventData['additional'] ?? null;
        if (!is_array($additional)) {
            return $eventData;
        }

        foreach ($additional as $key => $value) {
            if (!is_string($key) || array_key_exists($key, $eventData)) {
                continue;
            }

            $eventData[$key] = $value;
        }

        return $eventData;
    }

    /**
     * @param array<string, mixed> $eventData
     */
    private function detectTransactionId(array $eventData): ?string
    {
        foreach (['order_number', 'increment_id', 'transaction_number'] as $key) {
            $value = trim((string) ($eventData[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        $orderId = $eventData['order_id'] ?? null;
        if (is_scalar($orderId) && trim((string) $orderId) !== '') {
            return (string) $orderId;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $eventData
     */
    private function detectValue(array $eventData): ?float
    {
        foreach (['total', 'grand_total', 'subtotal'] as $key) {
            if (array_key_exists($key, $eventData) && is_numeric($eventData[$key])) {
                return (float) $eventData[$key];
            }
        }

        $price = $eventData['price'] ?? null;
        if (is_numeric($price)) {
            $quantity = $eventData['quantity'] ?? $eventData['qty'] ?? 1;
            if (is_numeric($quantity)) {
                return (float) $price * (float) $quantity;
            }

            return (float) $price;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $eventData
     * @return array<int, array<string, mixed>>
     */
    private function normalizeItems(array $eventData): array
    {
        $items = $eventData['items'] ?? null;
        if (is_array($items) && $items !== []) {
            $normalized = [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $normalized[] = $this->normalizeItem($item);
            }

            return array_values(array_filter($normalized, static fn(array $item): bool => $item !== []));
        }

        if (!array_key_exists('product_id', $eventData) && !array_key_exists('item_id', $eventData)) {
            return [];
        }

        return [$this->normalizeItem($eventData)];
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>
     */
    private function normalizeItem(array $item): array
    {
        $normalized = [];
        $productId = $item['product_id'] ?? $item['item_id'] ?? $item['id'] ?? null;
        if (is_scalar($productId) && trim((string) $productId) !== '') {
            $normalized['product_id'] = $productId;
            $normalized['item_id'] = (string) $productId;
        }

        $quantity = $item['qty'] ?? $item['quantity'] ?? $item['qty_ordered'] ?? 1;
        if (is_numeric($quantity)) {
            $quantity = (int) $quantity;
            $normalized['quantity'] = $quantity;
            $normalized['qty'] = $quantity;
        }

        if (array_key_exists('price', $item) && is_numeric($item['price'])) {
            $normalized['price'] = (float) $item['price'];
        }

        $name = trim((string) ($item['name'] ?? $item['content_name'] ?? ''));
        if ($name !== '') {
            $normalized['name'] = $name;
        }

        return $normalized;
    }
}

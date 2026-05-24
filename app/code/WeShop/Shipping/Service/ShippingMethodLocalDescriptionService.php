<?php

declare(strict_types=1);

namespace WeShop\Shipping\Service;

use Weline\Framework\App\State;

class ShippingMethodLocalDescriptionService
{
    /**
     * @var array<string, array<string, array{name: string, description: string}>>
     */
    private const DEFAULT_LOCAL_DESCRIPTIONS = [
        'flat_rate' => [
            'zh_Hans_CN' => [
                'name' => '固定运费',
                'description' => '按固定运费配送。',
            ],
            'en_US' => [
                'name' => 'Flat Rate',
                'description' => 'Standard delivery with a fixed shipping fee.',
            ],
        ],
        'free_shipping' => [
            'zh_Hans_CN' => [
                'name' => '免运费',
                'description' => '符合条件的订单免费配送。',
            ],
            'en_US' => [
                'name' => 'Free Shipping',
                'description' => 'Free delivery for eligible orders.',
            ],
        ],
        'local_pickup' => [
            'zh_Hans_CN' => [
                'name' => '到店自提',
                'description' => '到选择的本地门店自提订单。',
            ],
            'en_US' => [
                'name' => 'Local Pickup',
                'description' => 'Pick up your order at the selected local location.',
            ],
        ],
    ];

    /**
     * @param array<string, mixed> $method
     * @return array<string, mixed>
     */
    public function localize(array $method, ?string $locale = null): array
    {
        $code = trim((string) ($method['code'] ?? ''));
        if ($code === '') {
            return $method;
        }

        $description = $this->resolveLocalDescription($code, $method, $this->normalizeLocale($locale));
        if ($description === []) {
            return $method;
        }

        if (trim((string) ($description['name'] ?? '')) !== '') {
            $method['name'] = (string) $description['name'];
        }
        if (trim((string) ($description['description'] ?? '')) !== '') {
            $method['description'] = (string) $description['description'];
        }

        return $method;
    }

    /**
     * @param array<string, mixed> $method
     * @return array<string, string>
     */
    private function resolveLocalDescription(string $code, array $method, string $locale): array
    {
        $inline = $this->readInlineLocalDescription($method, $locale);
        if ($inline !== []) {
            return $inline;
        }

        return self::DEFAULT_LOCAL_DESCRIPTIONS[$code][$this->normalizeSupportedLocale($locale)] ?? [];
    }

    /**
     * @param array<string, mixed> $method
     * @return array<string, string>
     */
    private function readInlineLocalDescription(array $method, string $locale): array
    {
        $descriptions = $method['local_descriptions'] ?? $method['local_description'] ?? [];
        if (!\is_array($descriptions)) {
            return [];
        }

        $row = $descriptions[$locale] ?? $descriptions[$this->normalizeSupportedLocale($locale)] ?? [];
        if (!\is_array($row)) {
            return [];
        }

        return array_filter([
            'name' => trim((string) ($row['name'] ?? $row['title'] ?? '')),
            'description' => trim((string) ($row['description'] ?? '')),
        ], static fn(string $value): bool => $value !== '');
    }

    private function normalizeLocale(?string $locale): string
    {
        $locale = trim((string) ($locale ?: State::getLangLocal()));

        return $locale !== '' ? $locale : 'zh_Hans_CN';
    }

    private function normalizeSupportedLocale(string $locale): string
    {
        if (isset(self::DEFAULT_LOCAL_DESCRIPTIONS['flat_rate'][$locale])) {
            return $locale;
        }

        return str_starts_with($locale, 'zh') ? 'zh_Hans_CN' : 'en_US';
    }
}

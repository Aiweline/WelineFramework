<?php

declare(strict_types=1);

namespace Weline\Promotion\Service;

/** 活动主题后台表单：Website / Store / Channel 选项与商品类型。 */
final class PromotionThemeFormDataService
{
    /** @return array{websiteOptionsJson:string,storeOptionsJson:string,channelOptionsJson:string,productTypes:list<array<string,mixed>>} */
    public function build(int $websiteId = 0, string $storeCode = '', string $channelCode = ''): array
    {
        $websiteOptions = $this->loadWebsiteOptions();
        $storeOptions = $this->loadStoreOptions($websiteId);
        $channelOptions = $this->loadChannelOptions($websiteId, $storeCode);

        return [
            'websiteOptionsJson' => $this->json($websiteOptions),
            'storeOptionsJson' => $this->json($storeOptions),
            'channelOptionsJson' => $this->json($channelOptions),
            'productTypes' => $this->loadProductTypes($websiteId),
            'websiteSelectValue' => (string)max(0, $websiteId),
            'websiteSelectDisplay' => $this->resolveLabel($websiteOptions, (string)max(0, $websiteId)),
            'storeSelectValue' => $storeCode,
            'storeSelectDisplay' => $this->resolveLabel($storeOptions, $storeCode),
            'channelSelectValue' => $channelCode,
            'channelSelectDisplay' => $this->resolveLabel($channelOptions, $channelCode),
        ];
    }

    /** @return list<array{value:string,label:string,meta:string}> */
    private function loadWebsiteOptions(): array
    {
        try {
            $rows = w_query('websites', 'getWebsiteSelectOptions', [], 'backend');
        } catch (\Throwable) {
            $rows = [];
        }

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /** @return list<array{value:string,label:string,meta:string}> */
    private function loadStoreOptions(int $websiteId): array
    {
        if ($websiteId < 0) {
            return [];
        }

        try {
            $rows = w_query('websites', 'getStoreList', ['website_id' => $websiteId], 'backend');
        } catch (\Throwable) {
            $rows = [];
        }

        $options = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = trim((string)($row['code'] ?? $row['store_code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $name = trim((string)($row['name'] ?? $code));
            $options[] = [
                'value' => $code,
                'label' => $name,
                'meta' => $code,
            ];
        }

        return $options;
    }

    /** @return list<array{value:string,label:string,meta:string}> */
    private function loadChannelOptions(int $websiteId, string $storeCode): array
    {
        if ($websiteId < 0 || $storeCode === '') {
            return [];
        }

        try {
            $rows = w_query('websites', 'getChannelList', [
                'website_id' => $websiteId,
                'store_code' => $storeCode,
            ], 'backend');
        } catch (\Throwable) {
            $rows = [];
        }

        $options = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = trim((string)($row['code'] ?? $row['channel_code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $name = trim((string)($row['name'] ?? $code));
            $options[] = [
                'value' => $code,
                'label' => $name,
                'meta' => $code,
            ];
        }

        return $options;
    }

    /** @return list<array<string,mixed>> */
    private function loadProductTypes(int $websiteId): array
    {
        if ($websiteId < 0 || !function_exists('w_query')) {
            return [];
        }

        try {
            $payload = w_query('product_admin', 'creationContext', ['website_id' => $websiteId], 'backend');
        } catch (\Throwable) {
            return [];
        }

        $types = is_array($payload) ? ($payload['context']['product_types'] ?? []) : [];

        return is_array($types) ? array_values(array_filter($types, 'is_array')) : [];
    }

    /** @return array{label:string,level:string} */
    public function describeScope(int $websiteId, string $storeCode, string $channelCode): array
    {
        $websiteId = max(0, $websiteId);
        $storeCode = trim($storeCode);
        $channelCode = trim($channelCode);

        $websiteOptions = $this->loadWebsiteOptions();
        $parts = [];
        $level = 'website';
        // website_id=0 是「默认网站」，不是全站广播。
        $parts[] = $this->resolveLabel($websiteOptions, (string)$websiteId) ?: ($websiteId === 0 ? (string)__('默认网站') : ('W#' . $websiteId));

        $storeOptions = $this->loadStoreOptions($websiteId);
        $channelOptions = $storeCode !== '' ? $this->loadChannelOptions($websiteId, $storeCode) : [];

        if ($storeCode !== '') {
            $parts[] = $this->resolveLabel($storeOptions, $storeCode) ?: $storeCode;
            $level = 'store';
        } else {
            $parts[] = (string)__('全部店铺');
        }

        if ($channelCode !== '') {
            $parts[] = $this->resolveLabel($channelOptions, $channelCode) ?: $channelCode;
            $level = 'channel';
        } elseif ($storeCode !== '') {
            $parts[] = (string)__('全部渠道');
        }

        return [
            'label' => implode(' · ', $parts),
            'level' => $level,
        ];
    }

    /** @param list<array{value?:string,label?:string}> $options */
    private function resolveLabel(array $options, string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        foreach ($options as $option) {
            if ((string)($option['value'] ?? '') === $value) {
                return trim((string)($option['label'] ?? $value));
            }
        }

        return $value;
    }

    /** @param list<array<string,mixed>> $data */
    private function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]';
    }
}

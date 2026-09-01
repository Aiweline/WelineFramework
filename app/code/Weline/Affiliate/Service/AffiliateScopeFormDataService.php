<?php

declare(strict_types=1);

namespace Weline\Affiliate\Service;

/** 分销后台表单：Website / Store / Channel 选项与展示标签。 */
final class AffiliateScopeFormDataService
{
    /**
     * @return array{
     *     websiteOptionsJson:string,
     *     storeOptionsJson:string,
     *     channelOptionsJson:string,
     *     websiteSelectValue:string,
     *     websiteSelectDisplay:string,
     *     storeSelectValue:string,
     *     storeSelectDisplay:string,
     *     channelSelectValue:string,
     *     channelSelectDisplay:string
     * }
     */
    public function build(int $websiteId = 0, string $storeCode = '', string $channelCode = ''): array
    {
        $websiteOptions = $this->loadWebsiteOptions();
        $storeOptions = $this->loadStoreOptions($websiteId);
        $channelOptions = $this->loadChannelOptions($websiteId, $storeCode);

        return [
            'websiteOptionsJson' => $this->json($websiteOptions),
            'storeOptionsJson' => $this->json($storeOptions),
            'channelOptionsJson' => $this->json($channelOptions),
            'websiteSelectValue' => (string) max(0, $websiteId),
            'websiteSelectDisplay' => $this->resolveLabel($websiteOptions, (string) max(0, $websiteId)),
            'storeSelectValue' => $storeCode,
            'storeSelectDisplay' => $this->resolveLabel($storeOptions, $storeCode),
            'channelSelectValue' => $channelCode,
            'channelSelectDisplay' => $this->resolveLabel($channelOptions, $channelCode),
        ];
    }

    public function buildScopeLabel(int $websiteId, string $storeCode, string $channelCode): string
    {
        $parts = [];
        $formData = $this->build($websiteId, $storeCode, $channelCode);

        if ($websiteId > 0) {
            $label = trim((string) ($formData['websiteSelectDisplay'] ?? ''));
            $parts[] = $label !== '' ? $label : (string) $websiteId;
        }

        if ($storeCode !== '') {
            $label = trim((string) ($formData['storeSelectDisplay'] ?? ''));
            $parts[] = $label !== '' ? $label : $storeCode;
        }

        if ($channelCode !== '') {
            $label = trim((string) ($formData['channelSelectDisplay'] ?? ''));
            $parts[] = $label !== '' ? $label : $channelCode;
        }

        if ($parts === []) {
            return (string) \__('Global scope');
        }

        return implode(' / ', $parts);
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
        if ($websiteId <= 0) {
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
            $code = trim((string) ($row['code'] ?? $row['store_code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $name = trim((string) ($row['name'] ?? $code));
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
        if ($websiteId <= 0 || $storeCode === '') {
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
            $code = trim((string) ($row['code'] ?? $row['channel_code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $name = trim((string) ($row['name'] ?? $code));
            $options[] = [
                'value' => $code,
                'label' => $name,
                'meta' => $code,
            ];
        }

        return $options;
    }

    /**
     * @param list<array{value:string,label:string,meta:string}> $options
     */
    private function resolveLabel(array $options, string $value): string
    {
        if ($value === '' || $value === '0') {
            return '';
        }

        foreach ($options as $option) {
            if ((string) ($option['value'] ?? '') === $value) {
                return (string) ($option['label'] ?? $value);
            }
        }

        return $value;
    }

    /** @param list<array<string, mixed>> $options */
    private function json(array $options): string
    {
        return (string) json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}

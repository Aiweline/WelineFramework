<?php

declare(strict_types=1);

namespace Weline\Dropship\Service;

use Weline\Framework\Http\UrlInterface;

/**
 * 货源 Provider → 统一配置中心深链（须带 guide_key + guide_locate）。
 * 数据来自 Provider getConfigSchema()['config_center']，壳不硬编码供应商模块。
 */
final class DropshipChannelConfigDeepLinkBuilder
{
    public function __construct(
        private readonly UrlInterface $url,
    ) {
    }

    /**
     * @param array<string, mixed> $configCenter
     */
    public function build(array $configCenter, string $targetScope = 'default.default.default'): string
    {
        $module = trim((string)($configCenter['module'] ?? ''));
        if ($module === '') {
            return '';
        }

        $storageScope = strtolower(trim($targetScope));
        if ($storageScope === '' || $storageScope === 'global') {
            $storageScope = 'default.default.default';
        }

        $area = trim((string)($configCenter['area'] ?? 'backend'));
        if ($area === '') {
            $area = 'backend';
        }

        $guideKey = trim((string)($configCenter['guide_key'] ?? ''));
        $params = [
            'module' => $module,
            'area' => $area,
            'scope' => $storageScope,
            'target_scope' => $storageScope,
        ];

        if ($guideKey !== '') {
            $params['guide_key'] = $guideKey;
            $params['guide_locate'] = $guideKey;
            $title = trim((string)($configCenter['guide_title'] ?? ''));
            if ($title !== '') {
                $params['guide_title'] = $title;
            }
            $summary = trim((string)($configCenter['guide_summary'] ?? ''));
            if ($summary !== '') {
                $params['guide_summary'] = $summary;
            }
            $params['search'] = $guideKey;
            $params['q'] = $guideKey;
        }

        return (string)$this->url->getBackendUrl('weline_systemconfig/backend/config', $params, false);
    }
}

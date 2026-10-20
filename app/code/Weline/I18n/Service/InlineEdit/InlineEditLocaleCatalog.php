<?php

declare(strict_types=1);

namespace Weline\I18n\Service\InlineEdit;


use Weline\Framework\Env\WelineEnv;
use Weline\Framework\App\State;
use Weline\Framework\Context;
use Weline\Framework\Runtime\RequestContext;
use Weline\Websites\Model\Website;

/**
 * Locales for inline-edit popover: current + website; channel vs website scope tags.
 */
final class InlineEditLocaleCatalog
{
    /**
     * @return list<array{code:string,scope:string,current:bool}>
     */
    public function listForPopover(): array
    {
        $websiteId = Website::ID_DEFAULT;
        try {
            if (\class_exists(Context::class) && Context::hasCurrent()) {
                $websiteId = \max(0, RequestContext::getWelineWebsiteId());
            }
        } catch (\Throwable) {
            $websiteId = Website::ID_DEFAULT;
        }

        $channelId = null;
        $storeId = null;
        try {
            if (\class_exists(Context::class) && Context::hasCurrent()) {
                $channelId = \max(0, RequestContext::getWelineChannelId());
                $storeId = \max(0, RequestContext::getWelineStoreId());
            }
        } catch (\Throwable) {
        }

        $websiteCodes = $this->fetchWebsiteCodes($websiteId);
        $resolvedCodes = $this->fetchResolvedCodes($websiteId, $storeId, $channelId);
        if ($resolvedCodes === []) {
            $resolvedCodes = $websiteCodes;
        }

        $current = '';
        try {
            $current = \trim(\str_replace('-', '_', (string)WelineEnv::getLangLocal()));
        } catch (\Throwable) {
            $current = '';
        }

        $union = [];
        foreach (\array_merge($resolvedCodes, $websiteCodes, $current !== '' ? [$current] : []) as $code) {
            $code = \trim(\str_replace('-', '_', (string)$code));
            if ($code === '' || isset($union[$code])) {
                continue;
            }
            $union[$code] = true;
        }

        $out = [];
        $hasChannel = $channelId !== null && $channelId > 0;
        foreach (\array_keys($union) as $code) {
            $inResolved = \in_array($code, $resolvedCodes, true);
            $scope = 'website';
            if ($hasChannel && $inResolved) {
                $scope = 'channel';
            } elseif ($hasChannel && !$inResolved) {
                $scope = 'website';
            }
            $out[] = [
                'code' => $code,
                'scope' => $scope,
                'current' => $code === $current,
            ];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    private function fetchWebsiteCodes(int $websiteId): array
    {
        try {
            $result = \w_query('websites', 'getWebsiteLanguageCodes', ['website_id' => $websiteId]);

            return \is_array($result) ? \array_values(\array_map('strval', $result)) : [];
        } catch (\Throwable) {
            return ['zh_Hans_CN', 'en_US'];
        }
    }

    /**
     * @return list<string>
     */
    private function fetchResolvedCodes(int $websiteId, ?int $storeId, ?int $channelId): array
    {
        try {
            $result = \w_query('websites', 'getResolvedLanguageCodes', [
                'website_id' => $websiteId,
                'store_id' => $storeId,
                'channel_id' => $channelId,
            ]);

            return \is_array($result) ? \array_values(\array_map('strval', $result)) : [];
        } catch (\Throwable) {
            return [];
        }
    }
}

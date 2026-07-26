<?php

declare(strict_types=1);

namespace Weline\Visitor\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Visitor\Model\PixelChannel;

/**
 * B06：投放链接预览/复制（不接归因）。
 * URL = {website.base_url}{landing_path}?utm_source&utm_medium&utm_campaign&wch=code
 */
class PixelChannelLandingUrlService
{
    public function __construct(
        private ?PixelChannelCreateService $create = null
    ) {
    }

    /**
     * 解析站点基址。website_id=0 或查不到时回退 Env::getBaseUrl()。
     * $websiteUrlResolver 可注入：fn(int $websiteId): ?string，便于单测。
     */
    public function resolveBaseUrl(int $websiteId, ?callable $websiteUrlResolver = null): string
    {
        $resolved = null;
        if ($websiteUrlResolver !== null) {
            $resolved = $websiteUrlResolver($websiteId);
        } elseif ($websiteId > 0) {
            $resolved = $this->loadWebsiteUrl($websiteId);
        }
        $base = \trim((string)($resolved ?? ''));
        if ($base === '') {
            try {
                $base = \trim((string)Env::getInstance()->getBaseUrl());
            } catch (\Throwable) {
                $base = '';
            }
        }
        if ($base === '') {
            return '';
        }
        if (!\preg_match('#^https?://#i', $base)) {
            $base = 'https://' . \ltrim($base, '/');
        }

        return \rtrim($base, '/');
    }

    public function normalizeLandingPath(string $landingPath): string
    {
        $path = \trim($landingPath);
        if ($path === '' || $path === '/') {
            return '/';
        }
        // 禁止绝对外链 / 协议相对
        if (\preg_match('#^(https?:)?//#i', $path) === 1) {
            return '/';
        }
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }
        // 去掉 query/fragment（查询由 UTM 统一附加）
        $path = \explode('?', $path, 2)[0];
        $path = \explode('#', $path, 2)[0];

        return $path === '' ? '/' : $path;
    }

    /**
     * @param array<string,mixed> $channel
     * @return array{
     *   url: string,
     *   base_url: string,
     *   landing_path: string,
     *   query: string,
     *   showable: bool,
     *   params: array{utm_source: string, utm_medium: string, utm_campaign: string, wch: string}
     * }
     */
    public function buildPreview(
        array $channel,
        string $landingPath = '/',
        ?string $baseUrl = null,
        ?callable $websiteUrlResolver = null
    ): array {
        $code = \trim((string)($channel[PixelChannel::schema_fields_CODE] ?? $channel['code'] ?? ''));
        $trafficType = \trim((string)($channel[PixelChannel::schema_fields_TRAFFIC_TYPE] ?? $channel['traffic_type'] ?? PixelChannel::TRAFFIC_CUSTOM));
        $utm = $this->create()->buildUtmPackage(
            $code !== '' ? $code : 'your_code',
            $trafficType !== '' ? $trafficType : PixelChannel::TRAFFIC_CUSTOM,
            \array_key_exists('utm_source', $channel) ? (string)$channel['utm_source'] : null,
            \array_key_exists('utm_medium', $channel) ? (string)$channel['utm_medium'] : null,
        );
        if (\trim((string)($channel['utm_campaign'] ?? '')) !== '') {
            $utm['utm_campaign'] = \trim((string)$channel['utm_campaign']);
        }

        $websiteId = (int)($channel[PixelChannel::schema_fields_WEBSITE_ID] ?? $channel['website_id'] ?? 0);
        $base = $baseUrl !== null ? \rtrim(\trim($baseUrl), '/') : $this->resolveBaseUrl($websiteId, $websiteUrlResolver);
        $path = $this->normalizeLandingPath($landingPath);
        $enabled = !\array_key_exists('enabled', $channel) || (int)$channel['enabled'] === 1
            || $channel['enabled'] === true || $channel['enabled'] === '1';
        // 停用渠道：链接助手不展示（仍可算 query，url 置空）
        $showable = $enabled && $code !== '';

        $query = \http_build_query([
            'utm_source' => $utm['utm_source'],
            'utm_medium' => $utm['utm_medium'],
            'utm_campaign' => $utm['utm_campaign'],
            'wch' => $utm['wch'],
        ], '', '&', PHP_QUERY_RFC3986);

        $url = '';
        if ($showable && $base !== '') {
            $url = $base . ($path === '/' ? '/' : $path) . '?' . $query;
        }

        return [
            'url' => $url,
            'base_url' => $base,
            'landing_path' => $path,
            'query' => $query,
            'showable' => $showable,
            'params' => $utm,
        ];
    }

    /**
     * @param array<string,mixed> $channel
     */
    public function buildUrl(
        array $channel,
        string $landingPath = '/',
        ?string $baseUrl = null,
        ?callable $websiteUrlResolver = null
    ): string {
        return $this->buildPreview($channel, $landingPath, $baseUrl, $websiteUrlResolver)['url'];
    }

    private function loadWebsiteUrl(int $websiteId): ?string
    {
        if ($websiteId <= 0 || !\class_exists(\Weline\Websites\Model\Website::class)) {
            return null;
        }
        try {
            /** @var \Weline\Websites\Model\Website $website */
            $website = ObjectManager::getInstance(\Weline\Websites\Model\Website::class);
            $website->clear()->load($websiteId);
            if ((int)$website->getId() <= 0) {
                return null;
            }
            $url = \trim($website->getUrl());

            return $url !== '' ? $url : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function create(): PixelChannelCreateService
    {
        return $this->create ??= ObjectManager::getInstance(PixelChannelCreateService::class);
    }
}

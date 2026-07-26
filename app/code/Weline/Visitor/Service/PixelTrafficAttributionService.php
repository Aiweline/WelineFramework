<?php

declare(strict_types=1);

namespace Weline\Visitor\Service;

/**
 * 流量归因纯解析（工程计划 A03 / §2.1 S0–S1）。
 * 不查库；S2/S3 见 PixelChannelLookupService（B07/B09）。
 */
class PixelTrafficAttributionService
{
    private const PAID_MEDIUMS = [
        'cpc', 'ppc', 'paid', 'paid_social', 'paidsocial', 'display', 'cpm', 'cpa', 'retargeting',
    ];

    private const SOCIAL_HOST_NEEDLES = [
        'facebook.com', 'fb.com', 'fb.me', 'instagram.com', 'twitter.com', 'x.com', 't.co',
        'linkedin.com', 'lnkd.in', 'tiktok.com', 'pinterest.com', 'reddit.com',
        'weibo.com', 'douyin.com', 'xiaohongshu.com', 'xhslink.com',
    ];

    private const ORGANIC_MEDIUMS = ['organic', 'seo'];

    private const EMAIL_MEDIUMS = ['email', 'e-mail', 'newsletter', 'mail'];

    /**
     * @param array{
     *   url?: string,
     *   referer?: string,
     *   sticky?: array<string, mixed>|null,
     *   query?: array<string, mixed>|null
     * } $input
     * @return array{
     *   channel_code: string,
     *   channel_name: string,
     *   traffic_type: string,
     *   utm_source: string,
     *   utm_medium: string,
     *   utm_campaign: string,
     *   utm_content: string,
     *   utm_term: string,
     *   wch: string,
     *   gclid: string,
     *   fbclid: string,
     *   msclkid: string,
     *   referer_host: string,
     *   from_sticky: bool
     * }
     */
    public function resolve(array $input): array
    {
        $sticky = \is_array($input['sticky'] ?? null) ? $input['sticky'] : null;
        $fromSticky = $sticky !== null && $this->stickyHasMarketing($sticky);

        if ($fromSticky) {
            $pack = $this->normalizePack($sticky);
        } else {
            $query = \is_array($input['query'] ?? null)
                ? $input['query']
                : $this->parseQueryFromUrl((string)($input['url'] ?? ''));
            $pack = $this->normalizePack($query);
        }

        $referer = (string)($input['referer'] ?? '');
        $refererHost = $this->hostFromUrl($referer);

        $channelCode = $this->resolveChannelCode($pack);
        $trafficType = $this->resolveTrafficType($pack, $refererHost);

        return [
            'channel_code' => $channelCode,
            'channel_name' => '',
            'traffic_type' => $trafficType,
            'utm_source' => $pack['utm_source'],
            'utm_medium' => $pack['utm_medium'],
            'utm_campaign' => $pack['utm_campaign'],
            'utm_content' => $pack['utm_content'],
            'utm_term' => $pack['utm_term'],
            'wch' => $pack['wch'],
            'gclid' => $pack['gclid'],
            'fbclid' => $pack['fbclid'],
            'msclkid' => $pack['msclkid'],
            'referer_host' => $refererHost,
            'from_sticky' => $fromSticky,
        ];
    }

    /**
     * @param array<string, mixed> $raw
     * @return array{
     *   wch: string,
     *   utm_source: string,
     *   utm_medium: string,
     *   utm_campaign: string,
     *   utm_content: string,
     *   utm_term: string,
     *   gclid: string,
     *   fbclid: string,
     *   msclkid: string
     * }
     */
    private function normalizePack(array $raw): array
    {
        $wch = $this->scalar($raw['wch'] ?? $raw['channel_code'] ?? '');
        return [
            'wch' => $this->truncate($wch, 64),
            'utm_source' => $this->truncate($this->scalar($raw['utm_source'] ?? $raw['source'] ?? ''), 255),
            'utm_medium' => $this->truncate($this->scalar($raw['utm_medium'] ?? $raw['medium'] ?? ''), 255),
            'utm_campaign' => $this->truncate($this->scalar($raw['utm_campaign'] ?? $raw['campaign'] ?? ''), 255),
            'utm_content' => $this->truncate($this->scalar($raw['utm_content'] ?? $raw['content'] ?? ''), 255),
            'utm_term' => $this->truncate($this->scalar($raw['utm_term'] ?? $raw['term'] ?? ''), 255),
            'gclid' => $this->truncate($this->scalar($raw['gclid'] ?? ''), 255),
            'fbclid' => $this->truncate($this->scalar($raw['fbclid'] ?? ''), 255),
            'msclkid' => $this->truncate($this->scalar($raw['msclkid'] ?? ''), 255),
        ];
    }

    /**
     * @param array<string, string> $pack
     */
    private function resolveChannelCode(array $pack): string
    {
        if ($pack['wch'] !== '') {
            return $pack['wch'];
        }
        if ($pack['utm_campaign'] !== '') {
            return $this->truncate($pack['utm_campaign'], 64);
        }
        if ($pack['gclid'] !== '') {
            return 'google_ads';
        }
        if ($pack['fbclid'] !== '') {
            return 'meta_ads';
        }
        if ($pack['msclkid'] !== '') {
            return 'microsoft_ads';
        }

        return '';
    }

    /**
     * @param array<string, string> $pack
     */
    private function resolveTrafficType(array $pack, string $refererHost): string
    {
        if ($pack['gclid'] !== '' || $pack['fbclid'] !== '' || $pack['msclkid'] !== '') {
            return 'paid';
        }

        $medium = \strtolower($pack['utm_medium']);
        if ($medium !== '' && \in_array($medium, self::PAID_MEDIUMS, true)) {
            return 'paid';
        }
        if ($medium !== '' && \in_array($medium, self::EMAIL_MEDIUMS, true)) {
            return 'email';
        }
        if ($medium !== '' && \in_array($medium, self::ORGANIC_MEDIUMS, true)) {
            return 'organic';
        }
        if ($medium === 'social' || $medium === 'social-network' || $medium === 'social_media') {
            return 'social';
        }

        if ($refererHost !== '' && $this->isSocialHost($refererHost)) {
            return 'social';
        }

        if ($refererHost !== '') {
            return 'referral';
        }

        if ($pack['utm_source'] !== '' || $pack['utm_campaign'] !== '' || $pack['wch'] !== '') {
            return 'custom';
        }

        return 'direct';
    }

    /**
     * @param array<string, mixed> $sticky
     */
    private function stickyHasMarketing(array $sticky): bool
    {
        foreach (['wch', 'channel_code', 'utm_source', 'utm_medium', 'utm_campaign', 'source', 'medium', 'campaign', 'gclid', 'fbclid', 'msclkid'] as $key) {
            if ($this->scalar($sticky[$key] ?? '') !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private function parseQueryFromUrl(string $url): array
    {
        if ($url === '') {
            return [];
        }
        $parts = \parse_url($url);
        if (empty($parts['query'])) {
            return [];
        }
        $query = [];
        \parse_str((string)$parts['query'], $query);

        return \is_array($query) ? $query : [];
    }

    private function hostFromUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }
        $host = \parse_url($url, PHP_URL_HOST);
        if (!\is_string($host) || $host === '') {
            return '';
        }

        return \strtolower($host);
    }

    private function isSocialHost(string $host): bool
    {
        foreach (self::SOCIAL_HOST_NEEDLES as $needle) {
            if ($host === $needle || \str_ends_with($host, '.' . $needle)) {
                return true;
            }
        }

        return false;
    }

    private function scalar(mixed $value): string
    {
        if (\is_string($value) || \is_numeric($value)) {
            return \trim((string)$value);
        }

        return '';
    }

    private function truncate(string $value, int $max): string
    {
        if ($value === '' || \strlen($value) <= $max) {
            return $value;
        }

        return \substr($value, 0, $max);
    }
}

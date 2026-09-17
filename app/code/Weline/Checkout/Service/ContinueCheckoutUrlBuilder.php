<?php

declare(strict_types=1);

namespace Weline\Checkout\Service;

use Weline\Framework\App\Env;
use Weline\Framework\Manager\ObjectManager;
use Weline\Websites\Model\Website;

/**
 * Builds continue-checkout URLs for abandoned quoted sessions (not resumePaymentV2).
 */
final class ContinueCheckoutUrlBuilder
{
    public function __construct(
        private readonly ?string $storefrontBaseOverride = null,
    ) {
    }

    /**
     * @return array{continue_checkout_url:string,reachable:bool}
     */
    public function build(string $quoteToken, ?int $websiteId = null, string $locale = ''): array
    {
        $quoteToken = \trim($quoteToken);
        if ($quoteToken === '') {
            return ['continue_checkout_url' => '', 'reachable' => false];
        }
        $base = $this->storefrontBase($websiteId);
        if ($base === '') {
            return ['continue_checkout_url' => '', 'reachable' => false];
        }
        $path = '/checkout';
        $locale = \trim($locale);
        if ($locale !== '' && \strtolower($locale) !== 'default' && !\in_array($locale, ['zh_Hans_CN', 'zh_CN'], true)) {
            $path = '/' . \rawurlencode($locale) . '/checkout';
        }
        $url = \rtrim($base, '/') . $path . '?' . \http_build_query([
            'quote_token' => $quoteToken,
        ], '', '&', \PHP_QUERY_RFC3986);

        return [
            'continue_checkout_url' => $url,
            'reachable' => true,
        ];
    }

    private function storefrontBase(?int $websiteId): string
    {
        if ($this->storefrontBaseOverride !== null && $this->storefrontBaseOverride !== '') {
            return $this->appendDevPortIfNeeded(\rtrim($this->storefrontBaseOverride, '/'));
        }
        $websiteId = $websiteId !== null && $websiteId > 0 ? $websiteId : 0;
        try {
            /** @var Website $website */
            $website = ObjectManager::getInstance(Website::class);
            if ($websiteId > 0) {
                $website->load($websiteId);
            } else {
                $website->clear()->where(Website::schema_fields_ID, 0, '>=')->order(Website::schema_fields_ID, 'ASC')->find()->fetch();
            }
            if ($website->getId()) {
                $url = \trim((string)$website->getUrl());
                if ($url !== '') {
                    if (!\str_starts_with($url, 'http://') && !\str_starts_with($url, 'https://')) {
                        $url = 'https://' . \ltrim($url, '/');
                    }

                    return $this->appendDevPortIfNeeded(\rtrim($url, '/'));
                }
            }
        } catch (\Throwable) {
        }

        return '';
    }

    private function appendDevPortIfNeeded(string $url): string
    {
        $parts = \parse_url($url);
        if (!\is_array($parts) || !empty($parts['port'])) {
            return $url;
        }
        $host = \strtolower((string)($parts['host'] ?? ''));
        if ($host === '' || (!\str_ends_with($host, '.test.weline.com') && !\str_ends_with($host, '.weline.test'))) {
            return $url;
        }
        $port = 0;
        $reqHost = \trim((string)($_SERVER['HTTP_HOST'] ?? ''));
        if ($reqHost !== '' && \str_contains($reqHost, ':')) {
            $port = (int)\explode(':', $reqHost, 2)[1];
        }
        if ($port <= 0) {
            try {
                $raw = Env::get('wls.edge.nginx.listen_https', null);
                if (\is_numeric($raw)) {
                    $port = (int)$raw;
                }
            } catch (\Throwable) {
            }
        }
        if ($port <= 0 || $port === 80 || $port === 443) {
            return $url;
        }
        $scheme = (string)($parts['scheme'] ?? 'https');
        $path = (string)($parts['path'] ?? '');

        return $scheme . '://' . $host . ':' . $port . $path;
    }
}

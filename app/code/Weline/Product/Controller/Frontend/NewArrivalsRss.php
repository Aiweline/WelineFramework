<?php

declare(strict_types=1);

namespace Weline\Product\Controller\Frontend;

use Weline\Framework\App\Controller\FrontendController;
use Weline\Framework\Http\Response;
use Weline\Framework\Runtime\RequestContext;
use Weline\Framework\Runtime\ScopeIdentity;
use Weline\Framework\Xml\RssFeedWriter;
use Weline\Product\Service\StorefrontCatalogSurfaceResolver;
use Weline\Product\Service\StorefrontProductWidgetCatalog;

/** Reader RSS for new arrivals: /new-arrivals/rss.xml */
final class NewArrivalsRss extends FrontendController
{
    public function __construct(
        private readonly StorefrontProductWidgetCatalog $widgetCatalog,
        private readonly StorefrontCatalogSurfaceResolver $surfaces = new StorefrontCatalogSurfaceResolver(),
        private readonly RssFeedWriter $rssWriter = new RssFeedWriter(),
    ) {
    }

    public function index(): Response
    {
        $locale = '';
        $scope = RequestContext::scopeIdentity();
        if ($scope instanceof ScopeIdentity) {
            $locale = trim((string)($scope->locale ?? ''));
        }
        $surface = $this->surfaces->resolveSupported('/new-arrivals', $locale)
            ?? [
                'title' => (string)__('新品上架'),
                'seo_description' => (string)__('最新上架商品'),
            ];

        $baseUrl = $this->websiteBaseUrl();
        if ($baseUrl === '') {
            $host = trim((string)($this->request->getServer('HTTP_HOST') ?: ''));
            if ($host !== '') {
                $https = (string)($this->request->getServer('HTTPS') ?: '');
                $forwarded = strtolower((string)($this->request->getServer('HTTP_X_FORWARDED_PROTO') ?: ''));
                $scheme = ($https !== '' && $https !== 'off') || $forwarded === 'https' ? 'https' : 'http';
                $baseUrl = $scheme . '://' . $host;
            }
        }
        $items = $this->widgetCatalog->newArrivalCards(24, 365);
        $rssItems = [];
        $lastBuild = '';
        foreach ($items as $card) {
            if (!is_array($card)) {
                continue;
            }
            $title = trim((string)($card['name'] ?? ''));
            $link = $this->absoluteUrl(trim((string)($card['url'] ?? '')), $baseUrl);
            if ($title === '' || $link === '') {
                continue;
            }
            $pubDate = $this->toRfc822(isset($card['created_at']) ? (string)$card['created_at'] : null);
            if ($pubDate !== '' && ($lastBuild === '' || strtotime($pubDate) > strtotime($lastBuild))) {
                $lastBuild = $pubDate;
            }
            $description = trim((string)($card['campaign_label'] ?? ''));
            if ($description === '') {
                $description = $title;
            }
            $rssItems[] = [
                'title' => $title,
                'link' => $link,
                'guid' => $link,
                'pubDate' => $pubDate,
                'description' => $description,
            ];
        }

        $xml = $this->rssWriter->write(
            [
                'title' => (string)($surface['title'] ?? __('新品上架')),
                'link' => $this->absoluteUrl('/new-arrivals', $baseUrl),
                'description' => (string)($surface['seo_description'] ?? $surface['lede'] ?? __('最新上架商品')),
                'lastBuildDate' => $lastBuild !== '' ? $lastBuild : date('r'),
            ],
            $rssItems,
        );

        return Response::text($xml, 200, 'application/rss+xml; charset=utf-8');
    }

    private function websiteBaseUrl(): string
    {
        $scope = RequestContext::scopeIdentity();
        if ($scope instanceof ScopeIdentity) {
            $url = trim((string)($scope->websiteUrl ?? $scope->baseUrl ?? ''));
            if ($url !== '') {
                return rtrim($url, '/');
            }
        }

        return rtrim(trim((string)(\w_env('website.url', '') ?: '')), '/');
    }

    private function absoluteUrl(string $url, string $baseUrl): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url) === 1) {
            return $url;
        }
        $baseUrl = rtrim($baseUrl, '/');
        if ($baseUrl === '') {
            return '/' . ltrim($url, '/');
        }

        return $baseUrl . '/' . ltrim($url, '/');
    }

    private function toRfc822(?string $datetime): string
    {
        $datetime = trim((string)$datetime);
        if ($datetime === '') {
            return '';
        }
        $ts = strtotime($datetime);
        if ($ts === false) {
            return '';
        }

        return date('r', $ts);
    }
}

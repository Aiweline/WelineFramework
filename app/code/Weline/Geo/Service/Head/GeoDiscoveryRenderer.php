<?php

declare(strict_types=1);

namespace Weline\Geo\Service\Head;

use Weline\Framework\Manager\ObjectManager;
use Weline\Geo\Model\Feed;
use Weline\Geo\Model\WebsiteProtocolConfig;
use Weline\Seo\Api\Head\PageContextResolverInterface;
use Weline\Seo\Api\Protocol\WebsiteProtocolResolverInterface;

class GeoDiscoveryRenderer
{
    public function __construct(
        private readonly PageContextResolverInterface $resolver,
        private readonly WebsiteProtocolResolverInterface $websiteResolver,
        private readonly WebsiteProtocolConfig $protocolConfig
    ) {
    }

    /**
     * @param mixed $template
     * @param array<string, mixed> $options
     */
    public function render($template, array $options = []): string
    {
        $slot = (string) ($options['slot'] ?? 'head');
        if ($slot === 'head' && $this->claimTemplateRender($template, '__weline_geo_head_rendered')) {
            return '';
        }

        $context = $this->resolver->resolve($template, $options);
        $website = $this->websiteResolver->currentWebsite();
        $config = $this->loadWebsiteConfig($website->id);
        if (!$config->isLlmsEnabled() && !$config->isFeedEnabled()) {
            return '';
        }

        $feedUrl = $this->resolveFeedUrl($template) ?: $this->absoluteUrl($template, '/geo-feed.json');
        $rssUrl = $this->absoluteUrl($template, '/geo-feed.xml');
        $llmsUrl = $this->absoluteUrl($template, '/llms.txt');
        $title = $context->siteName !== '' ? $context->siteName : 'GEO Feed';
        $lines = ['<meta name="weline-geo" content="enabled">'];
        if ($config->isFeedEnabled()) {
            $lines[] = '<link rel="alternate" type="application/feed+json" title="' . $this->escape($title . ' JSON Feed') . '" href="' . $this->escape($feedUrl) . '">';
            $lines[] = '<link rel="alternate" type="application/rss+xml" title="' . $this->escape($title . ' RSS Feed') . '" href="' . $this->escape($rssUrl) . '">';
        }
        if ($config->isLlmsEnabled()) {
            $lines[] = '<link rel="alternate" type="text/plain" title="' . $this->escape($title . ' llms.txt') . '" href="' . $this->escape($llmsUrl) . '">';
        }

        return implode("\n", $lines);
    }

    private function claimTemplateRender($template, string $key): bool
    {
        if (!is_object($template) || !method_exists($template, 'getData') || !method_exists($template, 'setData')) {
            return false;
        }

        if (!empty($template->getData($key))) {
            return true;
        }

        $template->setData($key, true);
        return false;
    }

    private function resolveFeedUrl($template): string
    {
        if (is_object($template) && method_exists($template, 'getData')) {
            $explicit = trim((string) ($template->getData('geo_feed_url') ?? ''));
            if ($explicit !== '') {
                return $this->absoluteUrl($template, $explicit);
            }
        }

        try {
            /** @var Feed $feed */
            $feed = ObjectManager::getInstance(Feed::class);
            $row = $feed->where(Feed::schema_fields_IS_ENABLED, 1)
                ->order(Feed::schema_fields_ID, 'ASC')
                ->select()
                ->fetch();
            if ($row instanceof Feed && $row->getId()) {
                $url = trim((string) $row->getData(Feed::schema_fields_FEED_URL));
                if ($url !== '') {
                    return $this->absoluteUrl($template, $url);
                }
            }
            if (is_array($row)) {
                $url = trim((string) ($row[Feed::schema_fields_FEED_URL] ?? ''));
                if ($url !== '') {
                    return $this->absoluteUrl($template, $url);
                }
            }
        } catch (\Throwable) {
        }

        return '';
    }

    private function absoluteUrl($template, string $url): string
    {
        if ($url === '' || preg_match('/^https?:\/\//i', $url)) {
            return $url;
        }
        try {
            if (is_object($template) && isset($template->request)) {
                return rtrim((string) $template->request->getBaseUrl(), '/') . '/' . ltrim($url, '/');
            }
        } catch (\Throwable) {
        }
        return $url;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function loadWebsiteConfig(int $websiteId): WebsiteProtocolConfig
    {
        try {
            if ($websiteId > 0) {
                return $this->protocolConfig->loadByWebsiteId($websiteId);
            }
        } catch (\Throwable) {
        }

        return $this->protocolConfig->reset();
    }
}

<?php

declare(strict_types=1);

namespace Weline\Blog\Observer;

use Weline\Blog\Api\Data\BlogArticle;
use Weline\Blog\Api\Uri\BlogNamespace;
use Weline\Blog\Service\BlogSitemapUrlBuilder;
use Weline\Blog\Service\CmsBlogPageAdapter;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Seo\Api\Url\UrlChangeNotifierInterface;

final class CmsBlogPageSeoSyncObserver implements ObserverInterface
{
    public function __construct(
        private readonly CmsBlogPageAdapter $cmsAdapter,
        private readonly BlogSitemapUrlBuilder $sitemapBuilder,
    ) {
    }

    public function execute(Event &$event): void
    {
        if (!interface_exists(UrlChangeNotifierInterface::class)) {
            return;
        }

        $page = $event->getData('page');
        if (!is_array($page)) {
            return;
        }

        $pathGroup = trim(strtolower((string)($page['path_group'] ?? '')));
        if ($pathGroup !== BlogNamespace::PREFIX) {
            return;
        }

        $websiteId = (int)($page['website_id'] ?? $event->getData('website_id') ?? 0);
        $slug = trim(strtolower((string)($page['slug'] ?? '')));
        if ($slug === '') {
            return;
        }

        $article = $this->cmsAdapter->toBlogArticle($page);
        $action = strtolower(trim((string)($event->getData('action') ?? 'upsert')));
        if ($action === 'delete') {
            $this->notify($websiteId, $article, 'delete', (string)($event->getData('url') ?? $article->publicUrl));

            return;
        }

        if ((string)($page['status'] ?? '') !== 'published') {
            $this->notify($websiteId, $article, 'delete', $article->publicUrl);

            return;
        }

        $this->notify($websiteId, $article, 'upsert', $article->publicUrl);
    }

    private function notify(int $websiteId, BlogArticle $article, string $action, string $url): void
    {
        try {
            $notifier = \Weline\Framework\Manager\ObjectManager::getInstance(UrlChangeNotifierInterface::class);
            if (!$notifier instanceof UrlChangeNotifierInterface) {
                return;
            }
            $urlRow = $this->sitemapBuilder->articleToUrl($article);
            $notifier->notify([
                'module' => 'Weline_Blog',
                'scope' => 'blog_article',
                'action' => $action,
                'subject_type' => 'blog_article',
                'subject_id' => $article->entityId(),
                'url_key' => (string)($urlRow['url_key'] ?? ''),
                'url' => $url,
                'website_id' => $websiteId,
                'source' => 'Weline_Blog::cms_blog_page_sync',
            ]);
        } catch (\Throwable) {
        }
    }
}

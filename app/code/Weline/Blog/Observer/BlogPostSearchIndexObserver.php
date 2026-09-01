<?php

declare(strict_types=1);

namespace Weline\Blog\Observer;

use Weline\Blog\Model\Post;
use Weline\Blog\Service\BlogSearchIndexDocumentBuilder;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Search\Service\SearchProviderIndexService;

/** Incrementally sync blog post rows into Search provider index. */
final class BlogPostSearchIndexObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        if (!class_exists(SearchProviderIndexService::class)) {
            return;
        }

        /** @var array<string,mixed>|null $row */
        $row = $event->getData('post');
        if (!is_array($row)) {
            return;
        }

        $postId = (int)($row[Post::schema_fields_ID] ?? 0);
        $websiteId = (int)($row[Post::schema_fields_WEBSITE_ID] ?? 0);
        $slug = trim(strtolower((string)($row[Post::schema_fields_SLUG] ?? '')));
        if ($postId <= 0 || $slug === '') {
            return;
        }

        /** @var SearchProviderIndexService $indexService */
        $indexService = ObjectManager::getInstance(SearchProviderIndexService::class);
        /** @var BlogSearchIndexDocumentBuilder $builder */
        $builder = ObjectManager::getInstance(BlogSearchIndexDocumentBuilder::class);

        $action = trim((string)$event->getData('action'));
        $status = (string)($row[Post::schema_fields_STATUS] ?? '');
        if ($action === 'delete' || $status !== Post::STATUS_PUBLISHED) {
            $indexService->delete('blog', $websiteId, 'post:' . $postId);

            return;
        }

        $document = $builder->fromPostRow($row);
        if ($document === null) {
            $indexService->delete('blog', $websiteId, 'post:' . $postId);

            return;
        }

        $indexService->upsert($document);
    }
}

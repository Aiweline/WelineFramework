<?php

declare(strict_types=1);

namespace Weline\Blog\Observer;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * Provide storefront sample routes for blog shells.
 */
final class LayoutPreviewSampleObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $data = $event->getData('data');
        if (!$data instanceof DataObject) {
            return;
        }
        if ((bool)$data->getData('claimed')) {
            return;
        }

        $layoutPath = strtolower(trim(str_replace('\\', '/', (string)$data->getData('layout_path')), '/'));
        if (!in_array($layoutPath, ['blog', 'blog_category'], true)) {
            return;
        }

        $preferred = strtolower(trim((string)$data->getData('preferred_slug')));

        if ($layoutPath === 'blog_category') {
            $route = $preferred !== '' ? 'blog/category/' . $preferred : 'blog';
            $data->setData('claimed', true);
            $data->setData('preview_entity_route', $route);
            $data->setData('entity_slug', $preferred);
            $data->setData('sample_source', $preferred !== '' ? 'remembered' : 'hub');

            return;
        }

        try {
            $cmsAdapter = ObjectManager::getInstance(\Weline\Blog\Service\CmsBlogPageAdapter::class);
            $websiteId = max(0, (int)$data->getData('website_id'));
            $pages = $cmsAdapter->listPublishedBlogPages($websiteId, 1, 5);
            foreach ($pages as $page) {
                if (!is_array($page)) {
                    continue;
                }
                $slug = strtolower(trim((string)($page['slug'] ?? $page['url_key'] ?? '')));
                if ($slug === '') {
                    continue;
                }
                if ($preferred !== '' && $slug !== $preferred) {
                    continue;
                }
                $data->setData('claimed', true);
                $data->setData('preview_entity_route', 'blog/' . $slug);
                $data->setData('entity_slug', $slug);
                $data->setData('sample_source', $preferred !== '' ? 'remembered' : 'any_published');

                return;
            }
        } catch (\Throwable) {
            // Offline / missing CMS adapter.
        }

        try {
            /** @var \Weline\Blog\Model\Post $post */
            $post = ObjectManager::getInstance(\Weline\Blog\Model\Post::class);
            $rows = $post->reset()->clearData()
                ->where(\Weline\Blog\Model\Post::schema_fields_STATUS, \Weline\Blog\Model\Post::STATUS_PUBLISHED)
                ->limit(20)
                ->select()
                ->fetch()
                ->getItems();
            $resolver = null;
            $websiteId = max(0, (int)$data->getData('website_id'));
            $locale = trim((string)$data->getData('locale'));
            try {
                $resolver = ObjectManager::getInstance(\Weline\Blog\Service\BlogContentResolver::class);
                if ($locale === '') {
                    $locale = ObjectManager::getInstance(\Weline\Blog\Service\BlogScopeResolver::class)->locale();
                }
                if ($websiteId <= 0) {
                    $websiteId = ObjectManager::getInstance(\Weline\Blog\Service\BlogScopeResolver::class)->websiteId();
                }
            } catch (\Throwable) {
                $resolver = null;
            }
            foreach ($rows as $row) {
                if (!is_object($row) || !method_exists($row, 'getData')) {
                    continue;
                }
                $slug = strtolower(trim((string)$row->getData(\Weline\Blog\Model\Post::schema_fields_SLUG)));
                if ($slug === '') {
                    continue;
                }
                if ($preferred !== '' && $slug !== $preferred) {
                    continue;
                }
                if ($resolver instanceof \Weline\Blog\Service\BlogContentResolver) {
                    try {
                        $article = $resolver->resolveBySlug($websiteId, $locale, $slug, '');
                        if ($article === null) {
                            continue;
                        }
                    } catch (\Throwable) {
                        continue;
                    }
                }
                $data->setData('claimed', true);
                $data->setData('preview_entity_route', 'blog/' . $slug);
                $data->setData('entity_slug', $slug);
                $data->setData('sample_source', $preferred !== '' ? 'remembered' : 'any_published_post');

                return;
            }
        } catch (\Throwable) {
            // Offline / missing post table.
        }

        if ($preferred !== '') {
            $data->setData('claimed', true);
            $data->setData('preview_entity_route', 'blog/' . $preferred);
            $data->setData('entity_slug', $preferred);
            $data->setData('sample_source', 'remembered');

            return;
        }

        $data->setData('claimed', false);
        $data->setData('preview_entity_route', '');
        $data->setData('sample_source', 'none');
    }
}

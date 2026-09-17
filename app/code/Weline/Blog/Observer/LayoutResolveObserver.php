<?php

declare(strict_types=1);

namespace Weline\Blog\Observer;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

/**
 * Claim blog storefront paths onto shell layout_path=blog|blog_category.
 */
final class LayoutResolveObserver implements ObserverInterface
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

        $requestPath = strtolower(trim(str_replace('\\', '/', (string)$data->getData('request_path')), '/'));

        if ($requestPath === 'blog') {
            $data->setData('claimed', true);
            $data->setData('layout_path', 'blog_category');
            $data->setData('layout_option', 'default');
            $data->setData('entity_slug', '');
            $data->setData('entity_kind', '');

            return;
        }

        if (preg_match('#^blog/category/([a-z0-9][a-z0-9_-]*)$#D', $requestPath, $matches) === 1) {
            $data->setData('claimed', true);
            $data->setData('layout_path', 'blog_category');
            $data->setData('layout_option', 'default');
            $data->setData('entity_slug', strtolower((string)$matches[1]));
            $data->setData('entity_kind', 'blog_category');

            return;
        }

        if (preg_match('#^blog/([a-z0-9][a-z0-9_-]*)$#D', $requestPath, $matches) === 1) {
            $slug = strtolower((string)$matches[1]);
            if ($slug === 'category') {
                return;
            }
            $data->setData('claimed', true);
            $data->setData('layout_path', 'blog');
            $data->setData('layout_option', 'default');
            $data->setData('entity_slug', $slug);
            $data->setData('entity_kind', 'blog_post');
        }
    }
}

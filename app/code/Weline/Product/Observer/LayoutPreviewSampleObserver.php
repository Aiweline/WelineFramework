<?php

declare(strict_types=1);

namespace Weline\Product\Observer;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Service\StorefrontCatalogViewService;

/**
 * Provide storefront sample routes for product / category shells.
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
        // Only dynamic-slug shells need samples. Fixed hubs (products, …) are path=layout.
        if (!in_array($layoutPath, ['product', 'category'], true)) {
            return;
        }

        $preferred = strtolower(trim((string)$data->getData('preferred_slug')));

        try {
            /** @var StorefrontCatalogViewService $catalog */
            $catalog = ObjectManager::getInstance(StorefrontCatalogViewService::class);

            if ($layoutPath === 'product') {
                if ($preferred !== '') {
                    $offer = $catalog->publishedOfferBySlug($preferred);
                    if (is_array($offer) && ($offer['slug'] ?? '') !== '') {
                        $slug = strtolower(trim((string)$offer['slug']));
                        $data->setData('claimed', true);
                        $data->setData('preview_entity_route', 'product/' . $slug);
                        $data->setData('entity_slug', $slug);
                        $data->setData('sample_source', 'remembered');

                        return;
                    }
                }

                $summaries = $catalog->publishedOfferSummaries(1);
                $slug = strtolower(trim((string)($summaries[0]['slug'] ?? '')));
                if ($slug !== '') {
                    $data->setData('claimed', true);
                    $data->setData('preview_entity_route', 'product/' . $slug);
                    $data->setData('entity_slug', $slug);
                    $data->setData('sample_source', 'any_published');

                    return;
                }
            }

            if ($layoutPath === 'category') {
                $route = $preferred !== '' ? 'category/' . $preferred : 'categories';
                $data->setData('claimed', true);
                $data->setData('preview_entity_route', $route);
                $data->setData('entity_slug', $preferred);
                $data->setData('sample_source', $preferred !== '' ? 'remembered' : 'hub');

                return;
            }
        } catch (\Throwable) {
            // Unit / offline contexts may lack catalog DB.
        }

        $data->setData('claimed', false);
        $data->setData('preview_entity_route', '');
        $data->setData('entity_slug', '');
        $data->setData('sample_source', 'none');
    }
}

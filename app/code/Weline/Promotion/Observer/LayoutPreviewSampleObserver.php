<?php

declare(strict_types=1);

namespace Weline\Promotion\Observer;

use Weline\Framework\DataObject\DataObject;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Promotion\Service\PromotionActivityThemeService;
use Weline\Promotion\Service\PromotionStorefrontPageService;

/**
 * Provide storefront sample routes for promotion shell previews (same layout workspace).
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
        if ($layoutPath !== 'promotion') {
            return;
        }

        /** @var PromotionStorefrontPageService $pageService */
        $pageService = ObjectManager::getInstance(PromotionStorefrontPageService::class);
        $preferred = strtolower(trim((string)$data->getData('preferred_slug')));

        $candidates = [];
        if ($preferred !== '' && $preferred !== 'index') {
            $candidates[] = $preferred;
        }
        $candidates[] = 'deals';

        try {
            /** @var PromotionActivityThemeService $themes */
            $themes = ObjectManager::getInstance(PromotionActivityThemeService::class);
            foreach ($themes->listNavTabs() as $tab) {
                if (!is_array($tab)) {
                    continue;
                }
                $slug = strtolower(trim((string)($tab['slug'] ?? $tab['page_slug'] ?? '')));
                if ($slug !== '' && $slug !== 'index') {
                    $candidates[] = $slug;
                }
            }
        } catch (\Throwable) {
            // Fall back to built-in deals when theme listing is unavailable in unit context.
        }

        $seen = [];
        foreach ($candidates as $slug) {
            $slug = strtolower(trim((string)$slug));
            if ($slug === '' || isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;
            if (!$pageService->isPageAvailable($slug)) {
                continue;
            }

            $source = $preferred !== '' && $slug === $preferred
                ? 'remembered'
                : ($slug === 'deals' ? 'active_theme' : 'any_published');

            $data->setData('claimed', true);
            $data->setData('preview_entity_route', 'promotion/' . $slug);
            $data->setData('entity_slug', $slug);
            $data->setData('sample_source', $source);

            return;
        }

        $data->setData('claimed', true);
        $data->setData('preview_entity_route', 'promotion');
        $data->setData('entity_slug', '');
        $data->setData('sample_source', 'none');
    }
}

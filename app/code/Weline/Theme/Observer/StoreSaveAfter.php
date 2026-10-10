<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Theme\Service\WebsiteThemeBindingService;

/**
 * Persist Store-scoped storefront theme application (websites_theme_application).
 */
final class StoreSaveAfter implements ObserverInterface
{
    public function __construct(
        private readonly WebsiteThemeBindingService $bindings,
    ) {
    }

    public function execute(Event &$event): void
    {
        $eventData = $event->getData();
        if (!is_array($eventData)
            || !array_key_exists('store_id', $eventData)
            || $eventData['store_id'] === null
        ) {
            return;
        }

        $postData = $event->getData('post_data');
        if (!is_array($postData)) {
            return;
        }

        $extensions = $postData['extensions'] ?? [];
        $theme = is_array($extensions) ? ($extensions[WebsiteThemeBindingService::EXTENSION_KEY] ?? []) : [];
        if (!is_array($theme) || $theme === []) {
            return;
        }

        $store = $event->getData('store');
        if (!is_array($store)) {
            $store = [];
        }
        if (trim((string)($store['code'] ?? '')) === '' && is_array($eventData['store'] ?? null)) {
            $store = $eventData['store'];
        }
        if (!array_key_exists('store_id', $store)) {
            $store['store_id'] = $eventData['store_id'];
        }
        if (!array_key_exists('website_id', $store) && array_key_exists('website_id', $eventData)) {
            $store['website_id'] = $eventData['website_id'];
        }

        $connection = $event->getData('connection');
        $this->bindings->scheduleSaveFromStoreForm(
            $store,
            $theme,
            $connection instanceof ConnectionFactory ? $connection : null,
        );
    }
}

<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Theme\Service\WebsiteThemeBindingService;

/**
 * Persist Channel-scoped storefront theme application (websites_theme_application).
 */
final class ChannelSaveAfter implements ObserverInterface
{
    public function __construct(
        private readonly WebsiteThemeBindingService $bindings,
    ) {
    }

    public function execute(Event &$event): void
    {
        $eventData = $event->getData();
        if (!is_array($eventData)
            || !array_key_exists('channel_id', $eventData)
            || $eventData['channel_id'] === null
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

        $channel = $event->getData('channel');
        if (!is_array($channel)) {
            $channel = [];
        }
        if (trim((string)($channel['code'] ?? '')) === '' && is_array($eventData['channel'] ?? null)) {
            $channel = $eventData['channel'];
        }
        if (!array_key_exists('channel_id', $channel)) {
            $channel['channel_id'] = $eventData['channel_id'];
        }
        if (!array_key_exists('store_id', $channel) && array_key_exists('store_id', $eventData)) {
            $channel['store_id'] = $eventData['store_id'];
        }
        if (!array_key_exists('website_id', $channel) && array_key_exists('website_id', $eventData)) {
            $channel['website_id'] = $eventData['website_id'];
        }

        $connection = $event->getData('connection');
        $this->bindings->scheduleSaveFromChannelForm(
            $channel,
            $theme,
            $connection instanceof ConnectionFactory ? $connection : null,
        );
    }
}

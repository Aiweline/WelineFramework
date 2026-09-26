<?php

declare(strict_types=1);

namespace Weline\Theme\Observer;

use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Theme\Service\WebsiteThemeBindingService;

/**
 * Persist Website-scoped storefront theme_binding from website info form.
 */
final class WebsiteSaveAfter implements ObserverInterface
{
    public function __construct(
        private readonly WebsiteThemeBindingService $bindings,
    ) {
    }

    public function execute(Event &$event): void
    {
        $eventData = $event->getData();
        if (!is_array($eventData)
            || !array_key_exists('website_id', $eventData)
            || $eventData['website_id'] === null
        ) {
            return;
        }

        $websiteId = $this->normalizeWebsiteId($eventData['website_id']);
        $postData = $event->getData('post_data');
        if (!is_array($postData)) {
            return;
        }

        $extensions = $postData['extensions'] ?? [];
        $theme = is_array($extensions) ? ($extensions[WebsiteThemeBindingService::EXTENSION_KEY] ?? []) : [];
        if (!is_array($theme) || $theme === []) {
            return;
        }

        $website = $event->getData('website');
        if (!is_array($website)) {
            $website = [];
        }
        if (trim((string)($website['code'] ?? '')) === '' && is_array($eventData['website'] ?? null)) {
            $website = $eventData['website'];
        }

        $connection = $event->getData('connection');
        $this->bindings->scheduleSaveFromWebsiteForm(
            $websiteId,
            $website,
            $theme,
            $connection instanceof ConnectionFactory ? $connection : null,
        );
    }

    private function normalizeWebsiteId(mixed $value): int
    {
        if (is_int($value)) {
            $websiteId = $value;
        } elseif (is_string($value) && preg_match('/^\d+$/D', $value) === 1) {
            $websiteId = (int)$value;
        } else {
            throw new \InvalidArgumentException((string)__('website_id 必须是非负整数'));
        }
        if ($websiteId < 0) {
            throw new \InvalidArgumentException((string)__('website_id 不能为负数'));
        }

        return $websiteId;
    }
}

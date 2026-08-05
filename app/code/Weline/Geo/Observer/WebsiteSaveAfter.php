<?php

declare(strict_types=1);

namespace Weline\Geo\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Geo\Model\WebsiteProtocolConfig;

class WebsiteSaveAfter implements ObserverInterface
{
    public function __construct(
        private readonly WebsiteProtocolConfig $protocolConfig
    ) {
    }

    public function execute(Event &$event): void
    {
        $eventData = $event->getData();
        if (!is_array($eventData)
            || !array_key_exists('website_id', $eventData)
            || $eventData['website_id'] === null) {
            return;
        }
        $websiteId = $this->normalizeWebsiteId($eventData['website_id']);

        $postData = $event->getData('post_data');
        if (!is_array($postData)) {
            return;
        }

        $extensions = $postData['extensions'] ?? [];
        $geo = is_array($extensions) ? ($extensions['geo'] ?? []) : [];
        if (!is_array($geo) || $geo === []) {
            return;
        }

        $this->protocolConfig->saveForWebsite($websiteId, [
            'llms_enabled' => $this->flag($geo, 'llms_enabled', true),
            'feed_enabled' => $this->flag($geo, 'feed_enabled', true),
            'auto_push' => $this->flag($geo, 'auto_push', true),
            'feed_id' => (int)($geo['feed_id'] ?? 0),
            'llms_intro' => (string)($geo['llms_intro'] ?? ''),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function flag(array $data, string $key, bool $default): bool
    {
        if (!array_key_exists($key, $data)) {
            return $default;
        }

        $value = $data[$key];
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string)$value), ['1', 'true', 'yes', 'on'], true);
    }

    private function normalizeWebsiteId(mixed $value): int
    {
        if (is_int($value)) {
            $websiteId = $value;
        } elseif (is_string($value) && preg_match('/^\d+$/D', $value) === 1) {
            $websiteId = (int)$value;
        } else {
            throw new \InvalidArgumentException(__('website_id 必须是非负整数'));
        }
        if ($websiteId < 0) {
            throw new \InvalidArgumentException(__('website_id 不能为负数'));
        }
        return $websiteId;
    }
}

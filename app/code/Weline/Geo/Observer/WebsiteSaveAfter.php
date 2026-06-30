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
        $websiteId = (int)$event->getData('website_id');
        if ($websiteId <= 0) {
            return;
        }

        $postData = $event->getData('post_data');
        if (!is_array($postData)) {
            return;
        }

        $extensions = $postData['extensions'] ?? [];
        $geo = is_array($extensions) ? ($extensions['geo'] ?? []) : [];
        if (!is_array($geo) || $geo === []) {
            return;
        }

        try {
            $this->protocolConfig->saveForWebsite($websiteId, [
                'llms_enabled' => $this->flag($geo, 'llms_enabled', true),
                'feed_enabled' => $this->flag($geo, 'feed_enabled', true),
                'auto_push' => $this->flag($geo, 'auto_push', true),
                'feed_id' => (int)($geo['feed_id'] ?? 0),
                'llms_intro' => (string)($geo['llms_intro'] ?? ''),
            ]);
        } catch (\Throwable $e) {
            w_log_error(sprintf(
                '[Weline_Geo] website_save_after failed: website_id=%d, error=%s',
                $websiteId,
                $e->getMessage()
            ));
        }
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
}

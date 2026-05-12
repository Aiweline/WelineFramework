<?php

declare(strict_types=1);

namespace Weline\Seo\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Seo\Model\SeoWebsiteAccount;
use Weline\Seo\Model\WebsiteProtocolConfig;

class WebsiteSaveAfter implements ObserverInterface
{
    public function __construct(
        private readonly WebsiteProtocolConfig $protocolConfig,
        private readonly SeoWebsiteAccount $websiteAccount
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
        $seo = is_array($extensions) ? ($extensions['seo'] ?? []) : [];
        if (!is_array($seo)) {
            $seo = [];
        }

        if ($seo === [] && !array_key_exists('seo_account_id', $postData)) {
            return;
        }

        try {
            $this->protocolConfig->saveForWebsite($websiteId, [
                'robots_enabled' => $this->flag($seo, 'robots_enabled', true),
                'sitemap_enabled' => $this->flag($seo, 'sitemap_enabled', true),
                'google_extended' => (string)($seo['google_extended'] ?? 'allow'),
                'robots_extra' => (string)($seo['robots_extra'] ?? ''),
            ]);

            $hasAccountField = array_key_exists('account_id', $seo) || array_key_exists('seo_account_id', $postData);
            if ($hasAccountField) {
                $accountId = (int)($seo['account_id'] ?? $postData['seo_account_id'] ?? 0);
                if ($accountId > 0) {
                    $this->websiteAccount->bindWebsiteAccount($websiteId, $accountId, [
                        'is_auto_submit' => $this->flag($seo, 'auto_submit', true),
                    ]);
                } else {
                    $this->websiteAccount->unbindWebsite($websiteId);
                }
            }
        } catch (\Throwable $e) {
            w_log_error(sprintf(
                '[Weline_Seo] website_save_after failed: website_id=%d, error=%s',
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

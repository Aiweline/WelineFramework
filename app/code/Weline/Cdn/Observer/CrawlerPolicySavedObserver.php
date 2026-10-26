<?php

declare(strict_types=1);

namespace Weline\Cdn\Observer;

use Weline\Cdn\Api\CrawlerPolicyEdgePublisherInterface;
use Weline\Cdn\Service\CloudflareZoneAiSettingsService;
use Weline\Framework\Database\ConnectionFactory;
use Weline\Framework\Database\Transaction\TransactionCoordinatorInterface;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

/**
 * Best-effort edge sync after a website crawler policy save.
 *
 * Runs after commit so a Cloudflare HTTP call never extends the write transaction.
 */
final class CrawlerPolicySavedObserver implements ObserverInterface
{
    public function __construct(
        private readonly CrawlerPolicyEdgePublisherInterface $publisher,
        private readonly CloudflareZoneAiSettingsService $aiSettings,
        private readonly TransactionCoordinatorInterface $transactions,
    ) {
    }

    public function execute(Event &$event): void
    {
        $data = $event->getData();
        if (!\is_array($data) || !\array_key_exists('website_id', $data)) {
            return;
        }
        $websiteId = (int)($data['website_id'] ?? -1);
        if ($websiteId < 0) {
            return;
        }

        $aiOpen = !isset($data['rule']['ai_default_open'])
            || (bool)$data['rule']['ai_default_open'];
        $run = function () use ($websiteId, $aiOpen): void {
            try {
                $this->publisher->publish($websiteId);
            } catch (\Throwable) {
                // Edge failures are recorded in cdn_crawler_policy_state.
            }
            try {
                $this->aiSettings->applyForWebsite($websiteId, $aiOpen);
            } catch (\Throwable) {
                // Zone AI settings are best-effort; WAF state is independent.
            }
        };

        $connection = $data['connection'] ?? null;
        if ($connection instanceof ConnectionFactory) {
            try {
                $this->transactions->afterCommit($connection, 'cdn_crawler_policy:' . $websiteId, $run);

                return;
            } catch (\Throwable) {
                // Fall through to immediate best-effort when afterCommit is unavailable.
            }
        }

        $run();
    }
}

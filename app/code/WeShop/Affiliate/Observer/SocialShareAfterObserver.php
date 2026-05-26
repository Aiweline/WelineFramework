<?php

declare(strict_types=1);

namespace WeShop\Affiliate\Observer;

use WeShop\Affiliate\Service\AffiliateService;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;

class SocialShareAfterObserver implements ObserverInterface
{
    public function __construct(
        private readonly AffiliateService $affiliateService
    ) {
    }

    public function execute(Event &$event): void
    {
        $shareData = $event->getData('share_data');
        if (!is_array($shareData)) {
            return;
        }

        $shareCode = trim((string) ($shareData['affiliate_share_code'] ?? $shareData['share_code'] ?? ''));
        if ($shareCode === '') {
            return;
        }

        $share = $event->getData('share');
        $this->affiliateService->recordOutboundShare(
            $shareCode,
            (string) ($shareData['platform'] ?? 'social'),
            (int) ($shareData['customer_id'] ?? 0),
            [
                'source' => 'social_share_after',
                'social_share_id' => is_object($share) && method_exists($share, 'getId') ? (int) $share->getId() : 0,
            ]
        );
    }
}

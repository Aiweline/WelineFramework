<?php

declare(strict_types=1);

namespace Weline\Product\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Manager\ObjectManager;
use Weline\Product\Exception\ProductQuoteRequestStateTransitionException;
use Weline\Product\Model\ProductQuoteRequest;
use Weline\Product\Service\ProductQuoteMailReplySlotService;
use Weline\Product\Service\ProductQuoteRequestService;

/**
 * Closed loop: enterprise mail send with source=product_quote marks quote replied.
 */
final class QuoteRequestMailMessageSentObserver implements ObserverInterface
{
    public function execute(Event &$event): void
    {
        $source = trim((string)($event->getData('source') ?? ''));
        if ($source !== ProductQuoteMailReplySlotService::SOURCE) {
            return;
        }
        $quoteRequestId = max(0, (int)($event->getData('source_id') ?? 0));
        if ($quoteRequestId <= 0) {
            return;
        }

        try {
            ObjectManager::getInstance(ProductQuoteRequestService::class)
                ->transitionStatus(
                    $quoteRequestId,
                    ProductQuoteRequest::STATUS_REPLIED,
                    'mail_send_as'
                );
        } catch (ProductQuoteRequestStateTransitionException) {
            // Illegal transition or missing quote: do not fail the mail response.
        } catch (\Throwable) {
        }
    }
}

<?php

declare(strict_types=1);

namespace Weline\Payment\Service;

use Weline\Framework\Event\Event;
use Weline\Payment\Model\PaymentWebhookInbox;

final class DevRelayDispatcher
{
    public const EVENT_INBOX_RECEIVED = 'Weline_Payment::webhook_inbox_received';

    public function __construct(
        private readonly DevRelayGateService $gate,
        private readonly DevRelayEventStore $events,
        private readonly PaymentCallbackReceiver $receiver,
    ) {
    }

    public function handleEvent(Event &$event): void
    {
        if (!$this->gate->isOnlineRelayHost()) {
            return;
        }

        $data = $event->getData();
        if (!\is_array($data)) {
            return;
        }

        $inboxCode = trim((string) ($data['inbox_code'] ?? ''));
        if ($inboxCode === '') {
            return;
        }

        $inbox = $this->receiver->getInbox($inboxCode);
        if ($inbox === null) {
            return;
        }

        if ((string) ($inbox['status'] ?? '') !== PaymentWebhookInbox::STATUS_RECEIVED) {
            return;
        }

        $this->events->appendForActiveOnlineSession($inbox);
    }
}

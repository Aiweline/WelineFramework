<?php

declare(strict_types=1);

namespace Weline\Seo\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Http\Response;
use Weline\Framework\Runtime\Runtime;
use Weline\Seo\Service\StoreModeSeoResponseDecorator;

/**
 * Bridges WLS, FPM and early-FPC responses to the Store-mode SEO hard gate.
 */
final class StoreModeSeoResponseObserver implements ObserverInterface
{
    private const EVENT_RUN_AFTER = 'Weline_Framework::App::run_after';
    private const EVENT_RESPONSE_READY = 'Weline_Framework_Http::response_ready';
    private const EVENT_FPC_HIT = 'Weline_Framework_Fpc::cache_hit_response';

    public function __construct(
        private readonly StoreModeSeoResponseDecorator $decorator,
    ) {
    }

    public function execute(Event &$event): void
    {
        $eventName = $event->getName();
        $persistent = Runtime::isPersistent();

        if (($eventName === self::EVENT_RUN_AFTER && !$persistent)
            || ($eventName === self::EVENT_RESPONSE_READY && $persistent)) {
            return;
        }

        if ($eventName === self::EVENT_RUN_AFTER) {
            $result = $event->getData('result');
            if (!\is_string($result) && !$result instanceof Response) {
                return;
            }
            $event->setData('result', $this->decorator->decorate($result));
            return;
        }

        if (!\in_array($eventName, [self::EVENT_RESPONSE_READY, self::EVENT_FPC_HIT], true)) {
            return;
        }

        $response = $event->getData('response');
        if (!$response instanceof Response) {
            return;
        }
        $event->setData('response', $this->decorator->decorate($response));
    }
}

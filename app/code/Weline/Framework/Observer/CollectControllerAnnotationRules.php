<?php

declare(strict_types=1);

namespace Weline\Framework\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Router\Service\ControllerAnnotationRulesCollector;

/**
 * Dispatches controller-annotation-rules.v1 after route collection.
 */
final class CollectControllerAnnotationRules implements ObserverInterface
{
    public function __construct(
        private readonly ControllerAnnotationRulesCollector $collector,
    ) {
    }

    public function execute(Event &$event): void
    {
        $data = $event->getData();
        if (($data['model_only'] ?? false) === true) {
            return;
        }
        try {
            $this->collector->collectAll();
        } catch (\Throwable $exception) {
            if (defined('CLI') && CLI) {
                echo 'Controller annotation rules collection failed: ' . $exception->getMessage() . "\n";
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace Weline\Server\Observer;

use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Router\Service\ControllerAnnotationRulesCollector;
use Weline\Server\Service\AnnotationAttackRuleApplier;

final class ControllerAnnotationRulesHandler implements ObserverInterface
{
    public function __construct(
        private readonly AnnotationAttackRuleApplier $applier,
    ) {
    }

    public function execute(Event &$event): void
    {
        $data = $event->getData();
        if (($data['schema_version'] ?? '') !== ControllerAnnotationRulesCollector::SCHEMA) {
            return;
        }
        $rules = is_array($data['rules'] ?? null) ? $data['rules'] : [];
        if ($rules === []) {
            return;
        }
        try {
            $this->applier->apply($rules);
        } catch (\Throwable $exception) {
            w_log_error('WLS annotation attack rule apply failed: ' . $exception->getMessage());
        }
    }
}

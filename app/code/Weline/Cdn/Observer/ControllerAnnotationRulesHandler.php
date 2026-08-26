<?php

declare(strict_types=1);

namespace Weline\Cdn\Observer;

use Weline\Cdn\Service\CdnRuleCollector;
use Weline\Framework\Event\Event;
use Weline\Framework\Event\ObserverInterface;
use Weline\Framework\Router\Service\ControllerAnnotationRulesCollector;

/**
 * Applies cache/attack segments from controller_annotation_rules_collected.
 */
final class ControllerAnnotationRulesHandler implements ObserverInterface
{
    public function __construct(
        private readonly CdnRuleCollector $cdnRuleCollector,
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
            $this->cdnRuleCollector->applyAnnotationRules($rules);
        } catch (\Throwable $exception) {
            w_log_error('CDN annotation rule ingest failed: ' . $exception->getMessage());
        }
    }
}

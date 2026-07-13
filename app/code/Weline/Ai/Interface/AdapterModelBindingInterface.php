<?php

declare(strict_types=1);

namespace Weline\Ai\Interface;

/**
 * Allows scenario adapters to declare first-install model bindings.
 *
 * Runtime/admin configuration remains stored on ai_scenario_adapter. The
 * scanner only uses these bindings when creating a new adapter record, or when
 * an existing record has no model binding configured yet.
 */
/** @deprecated Implement \Weline\Ai\Api\AdapterModelBindingInterface. */
interface AdapterModelBindingInterface extends \Weline\Ai\Api\AdapterModelBindingInterface
{
    /**
     * @return array<string,string> Map primary modality to model code.
     */
    public function getDefaultModelBindings(): array;
}

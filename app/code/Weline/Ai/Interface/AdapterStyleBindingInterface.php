<?php

declare(strict_types=1);

namespace Weline\Ai\Interface;

/** @deprecated Implement \Weline\Ai\Api\AdapterStyleBindingInterface. */
interface AdapterStyleBindingInterface extends \Weline\Ai\Api\AdapterStyleBindingInterface
{
    /**
     * Adapter-level defaults are preferred style tags for this scenario.
     * Runtime scope can still choose `none` to disable style application.
     *
     * @return list<string>
     */
    public function getDefaultStyleCodes(): array;
}

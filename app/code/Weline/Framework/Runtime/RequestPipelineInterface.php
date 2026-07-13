<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

use Weline\Framework\App;

interface RequestPipelineInterface
{
    /**
     * Execute the framework-owned request stages after Runtime established the
     * request Context. Transport parsing, Worker policy, static/FPC L1 and
     * response serialization remain Runtime/transport responsibilities.
     */
    public function execute(
        App $app,
        bool $bootstrapRequestCycle = true,
        bool $startSession = true,
    ): RequestPipelineResult;
}

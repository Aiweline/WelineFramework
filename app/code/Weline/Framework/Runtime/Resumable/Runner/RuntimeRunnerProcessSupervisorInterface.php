<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable\Runner;

interface RuntimeRunnerProcessSupervisorInterface
{
    public function probe(RuntimeProcessIdentity $identity): RuntimeProcessProbe;

    public function forceTerminate(RuntimeProcessIdentity $identity): RuntimeProcessProbe;
}

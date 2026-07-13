<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Resumable\Runner;

interface RuntimeRunnerProcessLauncherInterface
{
    public function launch(RuntimeRunnerCommand $command): RuntimeProcessIdentity;
}

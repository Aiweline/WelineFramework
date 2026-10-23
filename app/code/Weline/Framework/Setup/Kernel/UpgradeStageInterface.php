<?php

declare(strict_types=1);

namespace Weline\Framework\Setup\Kernel;

interface UpgradeStageInterface
{
    public function key(): string;
    public function requires(): array;
    public function shouldRun(): bool;
    public function run(): StageResult;
}

<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime\Preload;

interface WorkerPreloadProviderInterface
{
    public function code(): string;

    public function phase(): string;

    public function priority(): int;

    public function isEnabled(WorkerPreloadContext $context): bool;

    public function preload(WorkerPreloadContext $context): WorkerPreloadResult;

    /**
     * @return list<string>
     */
    public function invalidationKeys(): array;
}

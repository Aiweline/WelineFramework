<?php

declare(strict_types=1);

namespace Weline\FakeData\Data;

use Weline\FakeData\Service\FakeDataRecordService;

class FakeDataContext
{
    public function __construct(
        private readonly array $args,
        private readonly string $seed,
        private readonly bool $reset,
        private readonly bool $dryRun,
        private readonly ?int $limit,
        private readonly FakeDataRecordService $recordService,
    ) {
    }

    public function getArgs(): array
    {
        return $this->args;
    }

    public function getSeed(): string
    {
        return $this->seed;
    }

    public function isReset(): bool
    {
        return $this->reset;
    }

    public function isDryRun(): bool
    {
        return $this->dryRun;
    }

    public function getLimit(): ?int
    {
        return $this->limit;
    }

    public function getRecordService(): FakeDataRecordService
    {
        return $this->recordService;
    }

    public function record(string $providerCode, string $entityType, int|string $entityId, string $stableKey, array $meta = []): void
    {
        if ($this->dryRun) {
            return;
        }
        $this->recordService->record($providerCode, $entityType, $entityId, $stableKey, $meta);
    }
}


<?php

declare(strict_types=1);

namespace Weline\Theme\Service\ThemeUpdate;

/**
 * 当前这次更新进入下一步之前，后面的变更只占用一个版本号。
 * 这次处理完，积压才成为下一个版本号。
 */
final class ThemeUpdateFlightRunner
{
    public function __construct(
        private readonly ThemeUpdateFlight $flight = new ThemeUpdateFlight(),
    ) {
    }

    /**
     * @param callable(string $batchId): array<string,mixed> $work
     * @return array<string,mixed>
     */
    public function execute(string $scope, callable $work): array
    {
        if (!$this->flight->tryHold($scope)) {
            return [
                'skipped' => 'coalesced',
                'batch_id' => $this->flight->ensureTail($scope),
            ];
        }
        try {
            return $work($this->flight->takeForProcessing($scope));
        } finally {
            $this->flight->release($scope);
        }
    }

    public function finishProcessing(string $scope): void
    {
        $this->flight->finishProcessing($scope);
    }

    public function ensureTail(string $scope): string
    {
        return $this->flight->ensureTail($scope);
    }

    public function activeBatchId(string $scope): string
    {
        return $this->flight->activeBatchId($scope);
    }

    public function tailBatchId(string $scope): string
    {
        return $this->flight->tailBatchId($scope);
    }
}

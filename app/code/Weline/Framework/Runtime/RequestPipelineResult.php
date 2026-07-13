<?php

declare(strict_types=1);

namespace Weline\Framework\Runtime;

use Weline\Framework\Http\Response;

/**
 * Immutable result of the shared FPM/WLS application pipeline.
 */
final readonly class RequestPipelineResult
{
    /**
     * @param array<string, mixed> $parsedUrl
     * @param array<string, float> $timings
     */
    public function __construct(
        public mixed $result,
        public ?Response $earlyResponse,
        public array $parsedUrl,
        public array $timings,
    ) {
    }

    public function output(): mixed
    {
        return $this->earlyResponse ?? $this->result;
    }

    public function isEarlyResponse(): bool
    {
        return $this->earlyResponse instanceof Response;
    }
}

<?php

declare(strict_types=1);

namespace Weline\Ai\Api\Configuration;

final readonly class UsageSummary
{
    public function __construct(
        public int $promptTokens,
        public int $completionTokens,
        public int $totalTokens,
        public float $actualCost,
    ) {
    }

    /** @return array{prompt_tokens:int,completion_tokens:int,total_tokens:int,actual_cost:float} */
    public function toArray(): array
    {
        return [
            'prompt_tokens' => $this->promptTokens,
            'completion_tokens' => $this->completionTokens,
            'total_tokens' => $this->totalTokens,
            'actual_cost' => $this->actualCost,
        ];
    }
}

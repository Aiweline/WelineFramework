<?php

declare(strict_types=1);

namespace Weline\Ai\Api\Image;

/** Data-only result returned by the text-to-image scenario binding command. */
final readonly class TextToImageScenarioBindingResult
{
    public const REASON_NO_ACTIVE_MODEL = 'no_active_text2image_model';
    public const REASON_SCENARIO_NOT_FOUND = 'scenario_adapter_not_found';
    public const REASON_ALREADY_BOUND = 'already_bound';
    public const REASON_UPDATED = 'updated';

    public function __construct(
        private bool $bound,
        private ?string $modelCode,
        private string $reason,
    ) {
    }

    public function isBound(): bool
    {
        return $this->bound;
    }

    public function getModelCode(): ?string
    {
        return $this->modelCode;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    /** @return array{bound:bool,model_code:?string,reason:string} */
    public function toArray(): array
    {
        return [
            'bound' => $this->bound,
            'model_code' => $this->modelCode,
            'reason' => $this->reason,
        ];
    }
}

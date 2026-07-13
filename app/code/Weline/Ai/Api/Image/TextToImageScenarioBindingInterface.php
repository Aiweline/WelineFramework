<?php

declare(strict_types=1);

namespace Weline\Ai\Api\Image;

/** Ai-owned command boundary for resolving and binding text-to-image scenarios. */
interface TextToImageScenarioBindingInterface
{
    public function resolveModelCode(TextToImageScenarioBindingRequest $request): ?string;

    public function bindIfNeeded(TextToImageScenarioBindingRequest $request): TextToImageScenarioBindingResult;
}

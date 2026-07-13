<?php

declare(strict_types=1);

namespace Weline\MediaManager\Service;

use Weline\Ai\Api\Image\TextToImageScenarioBindingInterface;
use Weline\Ai\Api\Image\TextToImageScenarioBindingRequest;
use Weline\Framework\Runtime\RuntimeProviderResolver;

/** Optional MediaManager adapter for the Ai-owned scenario binding command. */
class AiDrawModelBinder
{
    public const SCENARIO_CODE = 'media_manager_ai_draw';

    /** @var list<string> */
    private const REFERENCE_SCENARIO_CODES = [
        'pagebuilder_ai_site_assets',
    ];

    private const PLACEHOLDER_MODEL_CODE = '__media_manager_ai_draw_unbound__';

    private bool $providerResolved = false;
    private ?TextToImageScenarioBindingInterface $provider = null;

    public function __construct(
        private readonly RuntimeProviderResolver $runtimeProviders,
    ) {
    }

    public function resolveCurrentText2ImageModelCode(): ?string
    {
        return $this->resolveProvider()?->resolveModelCode($this->createRequest());
    }

    /** @return array{bound:bool,model_code:?string,reason:string} */
    public function bindIfNeeded(): array
    {
        $provider = $this->resolveProvider();
        if ($provider === null) {
            return [
                'bound' => false,
                'model_code' => null,
                'reason' => 'no_active_text2image_model',
            ];
        }

        return $provider->bindIfNeeded($this->createRequest())->toArray();
    }

    private function createRequest(): TextToImageScenarioBindingRequest
    {
        return new TextToImageScenarioBindingRequest(
            self::SCENARIO_CODE,
            self::REFERENCE_SCENARIO_CODES,
            self::PLACEHOLDER_MODEL_CODE,
        );
    }

    private function resolveProvider(): ?TextToImageScenarioBindingInterface
    {
        if ($this->providerResolved) {
            return $this->provider;
        }
        $this->providerResolved = true;

        if (!interface_exists(TextToImageScenarioBindingInterface::class)) {
            return null;
        }

        $provider = $this->runtimeProviders->resolve(TextToImageScenarioBindingInterface::class);
        $this->provider = $provider instanceof TextToImageScenarioBindingInterface ? $provider : null;

        return $this->provider;
    }
}

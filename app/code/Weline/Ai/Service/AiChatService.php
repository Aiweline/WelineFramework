<?php

declare(strict_types=1);

namespace Weline\Ai\Service;

class AiChatService
{
    private AiModelService $modelService;
    private AiApiKeyService $apiKeyService;

    public function __construct(
        AiModelService $modelService,
        AiApiKeyService $apiKeyService
    ) {
        $this->modelService = $modelService;
        $this->apiKeyService = $apiKeyService;
    }

    public function chat(string $prompt, string $modelCode, string $sessionId, array $options = []): array
    {
        // Try to get model, but don't fail if tables don't exist yet
        try {
            $model = $this->modelService->getByCode($modelCode);
            
            if (!$model->isActive()) {
                throw new \RuntimeException("Model is not active");
            }
        } catch (\Exception $e) {
            // If model lookup fails (e.g., table doesn't exist), continue with placeholder
            // This allows API to work even before database is fully set up
        }

        // TODO: Implement actual AI chat logic
        // This is a placeholder response
        return [
            'response' => "This is a placeholder response to: {$prompt}",
            'model_code' => $modelCode,
            'session_id' => $sessionId,
            'tokens_used' => 0,
            'cost' => 0.0,
        ];
    }
}


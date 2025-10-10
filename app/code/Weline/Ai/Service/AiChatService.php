<?php

declare(strict_types=1);

namespace Weline\Ai\Service;

class AiChatService
{
    public function __construct(
        private readonly AiModelService $modelService,
        private readonly AiApiKeyService $apiKeyService
    ) {}

    public function chat(string $prompt, string $modelCode, string $sessionId, array $options = []): array
    {
        // Get model
        $model = $this->modelService->getByCode($modelCode);

        if (!$model->isActive()) {
            throw new \RuntimeException("Model is not active");
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


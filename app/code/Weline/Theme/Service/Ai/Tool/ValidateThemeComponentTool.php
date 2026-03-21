<?php

declare(strict_types=1);

namespace Weline\Theme\Service\Ai\Tool;

use Weline\Ai\Interface\ToolInterface;
use Weline\Theme\Service\ThemeAiPayloadValidator;

class ValidateThemeComponentTool implements ToolInterface
{
    public function __construct(
        private readonly ThemeAiPayloadValidator $payloadValidator,
    ) {
    }

    public function getName(): string
    {
        return 'validate_theme_component';
    }

    public function getDescription(): string
    {
        return 'Validate the final virtual theme component payload before saving or publishing.';
    }

    public function getParameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'payload' => [
                    'description' => 'The candidate component payload as object or JSON string',
                ],
            ],
            'required' => ['payload'],
        ];
    }

    public function execute(array $args): mixed
    {
        $payload = $args['payload'] ?? [];
        if (is_string($payload)) {
            $parsed = $this->payloadValidator->extractPayload($payload);
            return $parsed === null
                ? ['valid' => false, 'errors' => ['payload is not valid JSON']]
                : $this->payloadValidator->validatePayload($parsed);
        }

        if (!is_array($payload)) {
            return ['valid' => false, 'errors' => ['payload must be an object or JSON string']];
        }

        return $this->payloadValidator->validatePayload($payload);
    }

    public function isEnabled(): bool
    {
        return true;
    }
}

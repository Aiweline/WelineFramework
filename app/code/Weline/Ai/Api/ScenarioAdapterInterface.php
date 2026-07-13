<?php

declare(strict_types=1);

namespace Weline\Ai\Api;

interface ScenarioAdapterInterface
{
    public function getCode(): string;

    public function getName(): string;

    public function getDescription(): string;

    public function getVersion(): string;

    public function getSupportedModelTypes(): array;

    public function adaptPrompt(string $prompt, array $params = []): string;

    public function processResponse(string $response, array $params = []): string;

    public function validateParams(array $params = []): array;

    public function getParamTemplate(): array;

    public function getExamples(): array;

    public function supportsModel(string $modelCode): bool;
}

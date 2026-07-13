<?php

declare(strict_types=1);

namespace Weline\Meta\Api;

interface ParamDefinitionNormalizerInterface
{
    /**
     * @param array<string|int, mixed> $params
     * @return array<string, array<string, mixed>>
     */
    public function normalizeDefinitions(array $params): array;

    /**
     * @param array<int, array<string, mixed>> $params
     * @return array<string, array<string, mixed>>
     */
    public function normalizeParsedParamList(array $params): array;

    /** @return array<string, array<string, mixed>> */
    public function extractParamAnnotations(string $content): array;

    /**
     * @param array<string, mixed> $definition
     * @return array<string, mixed>
     */
    public function normalizeDefinition(string $name, array $definition): array;
}

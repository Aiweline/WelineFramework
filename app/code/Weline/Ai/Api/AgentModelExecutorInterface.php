<?php

declare(strict_types=1);

namespace Weline\Ai\Api;

/**
 * Public execution boundary for Agent implementations that already own an AiModel.
 *
 * Provider selection and supplier-specific streaming stay inside Weline_Ai;
 * extension modules only submit the normalized messages and generation options.
 */
interface AgentModelExecutorInterface
{
    /**
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function generate(
        AiModel $model,
        array $messages,
        array $params = [],
        ?callable $streamCallback = null,
    ): array;
}

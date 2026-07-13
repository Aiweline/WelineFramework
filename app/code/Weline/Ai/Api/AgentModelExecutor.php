<?php

declare(strict_types=1);

namespace Weline\Ai\Api;

use Weline\Ai\Api\Provider\ProviderRuntimeInterface;

/**
 * Weline_Ai-owned adapter around its internal provider implementations.
 */
final class AgentModelExecutor implements AgentModelExecutorInterface
{
    public function __construct(
        private readonly ProviderRuntimeInterface $providerRuntime,
    ) {
    }

    public function generate(
        AiModel $model,
        array $messages,
        array $params = [],
        ?callable $streamCallback = null,
    ): array {
        $provider = $this->providerRuntime->getProvider($model);
        $request = $params;
        $request['messages'] = $messages;

        if (\method_exists($provider, 'generateStreamFull')) {
            $request['on_reasoning'] = $streamCallback === null
                ? null
                : static fn(string $chunk): bool => $streamCallback('thinking', [
                    'content' => $chunk,
                    'streaming' => true,
                ]) !== false;
            $request['on_content'] = $streamCallback === null
                ? null
                : static fn(string $chunk): bool => $streamCallback('chunk', [
                    'content' => $chunk,
                    'streaming' => true,
                ]) !== false;
            $request['on_heartbeat'] = $streamCallback === null
                ? null
                : static fn(): bool => $streamCallback('heartbeat', ['ts' => \time()]) !== false;

            $response = $provider->generateStreamFull($model, '', $request);
        } else {
            $response = $provider->generate($model, '', $request);
        }

        return \is_array($response) ? $response : [];
    }
}

<?php

declare(strict_types=1);

namespace Weline\Server\Service\Edge\Gateway;

/**
 * Normalizes the only capability that may be declared statically at startup.
 */
final class GatewayBackendCapabilityDeclaration
{
    /**
     * @param array<string,mixed> $gatewayConfig
     * @return array{
     *   backend_capability:'dynamic'|'stateless',
     *   backend_capability_source:'runtime_derived'|'runtime_config',
     *   backend_capability_generation:int
     * }
     */
    public function resolve(array $gatewayConfig, int $instanceGeneration): array
    {
        if ($instanceGeneration < 1) {
            throw new \InvalidArgumentException(
                'Gateway backend capability requires a positive instance generation.'
            );
        }
        $mode = \strtolower(\trim((string)(
            $gatewayConfig['backend_capability'] ?? ''
        )));
        if ($mode === '' || $mode === 'dynamic') {
            return [
                'backend_capability' => 'dynamic',
                'backend_capability_source' => 'runtime_derived',
                'backend_capability_generation' => $instanceGeneration,
            ];
        }
        if ($mode !== 'stateless') {
            throw new \InvalidArgumentException(
                'Gateway backend capability must be stateless or omitted; shared Session is runtime-probed.'
            );
        }
        return [
            'backend_capability' => 'stateless',
            'backend_capability_source' => 'runtime_config',
            'backend_capability_generation' => $instanceGeneration,
        ];
    }
}

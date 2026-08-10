<?php

declare(strict_types=1);

namespace Weline\Ai\Service\Query;

use Weline\Framework\Service\Query\AdminControllerBridge;

final class AiAgentQuerySupport
{
    /**
     * @param array<string, mixed> $params
     */
    public function execute(string $operation, array $params): mixed
    {
        return match ($operation) {
            'agentCatalog' => $this->invoke(['postCatalog', 'getCatalog'], $params),
            'agentSave' => $this->invoke(['postSave'], $params),
            'agentSetActive' => $this->invoke(['postSetActive'], $params),
            'agentSaveTool' => $this->invoke(['postSaveTool'], $params),
            'agentSetToolStatus' => $this->invoke(['postSetToolStatus'], $params),
            'agentScan' => $this->invoke(['postScan', 'scan'], $params),
            default => throw new \InvalidArgumentException('Unsupported AI agent operation: ' . $operation),
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getOperationDescriptors(): array
    {
        $read = static fn(string $name, string $summary, array $params): array => [
            'name' => $name,
            'frontend' => true,
            'mode' => 'read',
            'graph' => false,
            'cost' => 1,
            'auth' => 'backend',
            'params' => $params,
            'returns' => ['type' => 'array'],
            'summary' => $summary,
        ];
        $write = static fn(string $name, string $summary, array $params, int $cost = 2): array => [
            'name' => $name,
            'frontend' => true,
            'mode' => 'write',
            'graph' => false,
            'cost' => $cost,
            'auth' => 'backend',
            'params' => $params,
            'returns' => ['type' => 'array'],
            'summary' => $summary,
        ];

        return [
            $read('agentCatalog', 'List AI agent catalog', [
                'code' => ['type' => 'string', 'required' => false, 'max_length' => 255],
                'include_inactive' => ['type' => 'bool', 'required' => false],
            ]),
            $write('agentSave', 'Save AI agent name/description overrides', [
                'code' => ['type' => 'string', 'required' => true, 'max_length' => 255],
                'name_override' => ['type' => 'string', 'required' => false, 'max_length' => 255],
                'description_override' => ['type' => 'string', 'required' => false],
            ]),
            $write('agentSetActive', 'Enable or disable an AI agent', [
                'code' => ['type' => 'string', 'required' => true, 'max_length' => 255],
                'is_active' => ['type' => 'bool', 'required' => true],
            ]),
            $write('agentSaveTool', 'Save AI agent tool description override', [
                'agent_code' => ['type' => 'string', 'required' => true, 'max_length' => 255],
                'tool_name' => ['type' => 'string', 'required' => true, 'max_length' => 255],
                'description_override' => ['type' => 'string', 'required' => false],
            ]),
            $write('agentSetToolStatus', 'Enable or disable an AI agent tool', [
                'agent_code' => ['type' => 'string', 'required' => true, 'max_length' => 255],
                'tool_name' => ['type' => 'string', 'required' => true, 'max_length' => 255],
                'is_enabled' => ['type' => 'bool', 'required' => true],
            ]),
            $write('agentScan', 'Scan and register AI agents', [], 3),
        ];
    }

    public function backendAclSourceId(string $operation): string
    {
        return match ($operation) {
            'agentCatalog' => 'Weline_Ai::ai_agent_index',
            'agentSave', 'agentSaveTool' => 'Weline_Ai::ai_agent_save',
            'agentSetActive', 'agentSetToolStatus' => 'Weline_Ai::ai_agent_toggle',
            'agentScan' => 'Weline_Ai::ai_agent_scan',
            default => throw new \LogicException('Backend ACL is not mapped for AI agent operation: ' . $operation),
        };
    }

    /**
     * @param list<string> $methodCandidates
     * @param array<string, mixed> $params
     */
    private function invoke(array $methodCandidates, array $params): mixed
    {
        $bodyParams = $this->normalizeBodyParams($params);

        return AdminControllerBridge::invoke(
            \Weline\Ai\Controller\Backend\Agent::class,
            $methodCandidates,
            [],
            $bodyParams,
            'POST',
            ''
        );
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function normalizeBodyParams(array $params): array
    {
        if (isset($params['payload']) && is_array($params['payload'])) {
            $params = array_merge($params['payload'], $params);
            unset($params['payload']);
        }

        return $params;
    }
}

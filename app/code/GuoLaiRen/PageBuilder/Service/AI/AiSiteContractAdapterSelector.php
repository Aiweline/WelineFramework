<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI;

use GuoLaiRen\PageBuilder\Service\AI\Contract\ContractType;

final class AiSiteContractAdapterSelector
{
    public const ADAPTER_JSON_STRICT = 'json_strict';
    public const ADAPTER_REASONING_STRONG = 'reasoning_strong';
    public const ADAPTER_COPY_MATURE = 'copy_mature';
    public const ADAPTER_RULES_ENGINE = 'rules_engine';

    /**
     * @param array<string, mixed> $overrides
     * @return array{adapter_type:string,stage:string,role:string,request_params:array<string,mixed>}
     */
    public function select(string $stage, string $role = '', array $overrides = []): array
    {
        $adapterType = $this->adapterTypeFor($stage, $role);
        $params = [
            'response_format' => ['type' => 'json_object'],
        ];

        if ($stage === ContractType::STAGE_BUILD_PLAN) {
            $params['disable_conversation_history'] = true;
            $params['disable_conversation_persist'] = true;
        }

        if ($adapterType === self::ADAPTER_RULES_ENGINE) {
            $params = [
                'rules_engine' => true,
                'response_format' => ['type' => 'json_object'],
            ];
        }

        return [
            'adapter_type' => $adapterType,
            'stage' => $stage,
            'role' => $role,
            'request_params' => \array_replace_recursive($params, $overrides),
        ];
    }

    private function adapterTypeFor(string $stage, string $role): string
    {
        if ($stage === ContractType::STAGE_QA || $role === 'qa') {
            return self::ADAPTER_RULES_ENGINE;
        }
        if ($role === 'copy') {
            return self::ADAPTER_COPY_MATURE;
        }
        if ($role === 'reasoning') {
            return self::ADAPTER_REASONING_STRONG;
        }

        return self::ADAPTER_JSON_STRICT;
    }
}

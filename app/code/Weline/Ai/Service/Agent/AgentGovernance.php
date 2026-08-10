<?php

declare(strict_types=1);

namespace Weline\Ai\Service\Agent;

use Weline\Ai\Api\AgentCatalogInterface;
use Weline\Framework\Manager\ObjectManager;

/**
 * Applies catalog overrides and tool enablement to function-calling tool defs.
 */
final class AgentGovernance
{
    public function __construct(
        private readonly ?AgentCatalogInterface $catalog = null,
    ) {
    }

    /**
     * @param list<array<string, mixed>|object> $tools
     * @return list<array<string, mixed>>
     */
    public static function governToolDefs(array $tools, ?string $agentCode = null): array
    {
        try {
            return ObjectManager::getInstance(self::class)->applyToToolDefs($tools, $agentCode);
        } catch (\Throwable) {
            return self::applyPolicyToToolDefs($tools, []);
        }
    }

    /**
     * @param list<array<string, mixed>|object> $tools
     * @return list<array<string, mixed>>
     */
    public function applyToToolDefs(array $tools, ?string $agentCode = null): array
    {
        $code = trim((string)($agentCode ?? AgentExecutionContext::currentAgentCode() ?? ''));
        if ($code === '') {
            return self::applyPolicyToToolDefs($tools, []);
        }

        try {
            $policy = $this->catalog()->toolPolicy($code);
        } catch (\Throwable) {
            $policy = [];
        }

        return self::applyPolicyToToolDefs($tools, $policy);
    }

    /**
     * @param list<array<string, mixed>|object> $tools
     * @param array<string, array{enabled?:bool,present?:bool,description_override?:?string}> $policy
     * @return list<array<string, mixed>>
     */
    public static function applyPolicyToToolDefs(array $tools, array $policy): array
    {
        $result = [];
        foreach ($tools as $tool) {
            $def = self::normalizeToolDef($tool);
            if ($def === null) {
                continue;
            }
            $name = $def['name'];
            $rule = $policy[$name] ?? null;
            if (is_array($rule)) {
                $present = array_key_exists('present', $rule) ? (bool)$rule['present'] : true;
                $enabled = array_key_exists('enabled', $rule) ? (bool)$rule['enabled'] : true;
                if (!$present || !$enabled) {
                    continue;
                }
                $override = self::nullableString($rule['description_override'] ?? null);
                if ($override !== null) {
                    $def['description'] = $override;
                }
            }
            $result[] = $def;
        }

        return $result;
    }

    /**
     * @param array<string, mixed>|object $tool
     * @return array{name:string,description:string,parameters:array<string,mixed>}|null
     */
    private static function normalizeToolDef(mixed $tool): ?array
    {
        if (is_object($tool)) {
            if (!method_exists($tool, 'getName')) {
                return null;
            }
            $name = trim((string)$tool->getName());
            if ($name === '') {
                return null;
            }
            $description = method_exists($tool, 'getDescription') ? (string)$tool->getDescription() : '';
            $parameters = method_exists($tool, 'getParameters') ? $tool->getParameters() : ['type' => 'object', 'properties' => new \stdClass()];
            if (!is_array($parameters)) {
                $parameters = ['type' => 'object', 'properties' => new \stdClass()];
            }

            return [
                'name' => $name,
                'description' => $description,
                'parameters' => $parameters,
            ];
        }
        if (!is_array($tool)) {
            return null;
        }
        $name = trim((string)($tool['name'] ?? ''));
        if ($name === '') {
            return null;
        }
        $parameters = $tool['parameters'] ?? ['type' => 'object', 'properties' => new \stdClass()];
        if (!is_array($parameters) && !($parameters instanceof \stdClass)) {
            $parameters = ['type' => 'object', 'properties' => new \stdClass()];
        }

        return [
            'name' => $name,
            'description' => (string)($tool['description'] ?? ''),
            'parameters' => $parameters,
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $text = trim((string)$value);

        return $text === '' ? null : $text;
    }

    private function catalog(): AgentCatalogInterface
    {
        if ($this->catalog instanceof AgentCatalogInterface) {
            return $this->catalog;
        }

        return ObjectManager::getInstance(AgentCatalogInterface::class);
    }
}

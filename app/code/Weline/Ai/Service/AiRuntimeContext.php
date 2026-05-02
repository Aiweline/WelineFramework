<?php

declare(strict_types=1);

namespace Weline\Ai\Service;

use Weline\Framework\Runtime\RequestContext;

final class AiRuntimeContext
{
    public const REQUEST_DEFAULT_PARAMS_KEY = 'weline.ai.runtime.default_params';

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public static function mergeDefaultParams(array $params): array
    {
        $defaults = self::getDefaultParams();
        if ($defaults === []) {
            return $params;
        }

        return \array_replace($defaults, $params);
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function setDefaultParams(array $params): void
    {
        RequestContext::set(self::REQUEST_DEFAULT_PARAMS_KEY, $params);
    }

    public static function hasDefaultParams(): bool
    {
        return RequestContext::has(self::REQUEST_DEFAULT_PARAMS_KEY);
    }

    /**
     * @return array<string, mixed>
     */
    public static function getDefaultParams(): array
    {
        $params = RequestContext::get(self::REQUEST_DEFAULT_PARAMS_KEY, []);
        return \is_array($params) ? $params : [];
    }

    public static function removeDefaultParams(): void
    {
        RequestContext::remove(self::REQUEST_DEFAULT_PARAMS_KEY);
    }

    /**
     * @return array<string, mixed>
     */
    public static function thinkingModeParams(string $reasoningEffort = 'medium'): array
    {
        $params = [
            'thinking_mode' => true,
        ];
        $reasoningEffort = \trim($reasoningEffort);
        if ($reasoningEffort !== '') {
            $params['reasoning_effort'] = $reasoningEffort;
        }

        return $params;
    }

    private function __construct()
    {
    }
}

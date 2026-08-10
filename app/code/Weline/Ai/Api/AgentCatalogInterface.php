<?php

declare(strict_types=1);

namespace Weline\Ai\Api;

use Weline\Ai\Api\ToolInterface;

/**
 * Public catalog for registered agents, description overrides, and tool enablement.
 */
interface AgentCatalogInterface
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listCatalog(bool $includeInactive = true): array;

    /** @return array<string, mixed>|null */
    public function findByCode(string $code): ?array;

    /**
     * @return array<string, array{enabled:bool,present:bool,description_override:?string}>
     */
    public function toolPolicy(string $agentCode): array;

    /**
     * @param list<ToolInterface|object> $tools
     */
    public function syncToolsFromAgent(string $agentCode, array $tools): void;

    /** @param list<string> $presentCodes */
    public function markMissingAgents(array $presentCodes): void;

    /** @return array<string, mixed> */
    public function saveOverrides(string $code, ?string $nameOverride, ?string $descriptionOverride): array;

    /** @return array<string, mixed> */
    public function setActive(string $code, bool $active): array;

    /** @return array<string, mixed> */
    public function saveToolOverride(string $agentCode, string $toolName, ?string $descriptionOverride): array;

    /** @return array<string, mixed> */
    public function setToolEnabled(string $agentCode, string $toolName, bool $enabled): array;
}

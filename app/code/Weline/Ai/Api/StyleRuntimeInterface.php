<?php

declare(strict_types=1);

namespace Weline\Ai\Api;

/**
 * Public style-selection contract used by optional scenario modules.
 */
interface StyleRuntimeInterface
{
    public const MODE_AUTO = 'auto';
    public const MODE_MANUAL = 'manual';
    public const MODE_NONE = 'none';

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function resolveSelectionForScope(
        array $scope,
        int $adminId,
        bool $lock = false,
        string $adapterCode = ''
    ): array;

    /**
     * @param list<string> $temporarySkillCodes
     * @return array{items:list<array<string,mixed>>,default_skill_codes:list<string>,warnings:list<string>}
     */
    public function buildSkillCatalog(
        string $adapterCode,
        array $temporarySkillCodes = [],
        bool $includeInactive = false,
    ): array;

    /**
     * @param list<string> $temporaryStyleCodes
     * @return array{items:list<array<string,mixed>>,default_style_codes:list<string>,manual_style_codes:list<string>,warnings:list<string>}
     */
    public function buildStyleCatalog(
        string $adapterCode,
        array $temporaryStyleCodes = [],
        int $adminId = 0,
        bool $includeInactive = false,
    ): array;

    /** @param list<string> $styleCodes @return array<string,mixed> */
    public function resolveStyleSnapshot(
        array $styleCodes,
        int $adminId,
        string $reason = 'Theme visual editor selection',
    ): array;
}

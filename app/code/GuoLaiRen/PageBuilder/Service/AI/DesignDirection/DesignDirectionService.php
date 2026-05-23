<?php

declare(strict_types=1);

namespace GuoLaiRen\PageBuilder\Service\AI\DesignDirection;

use GuoLaiRen\PageBuilder\Extends\Module\Weline_Ai\Style\PageBuilderStyleProvider;
use Weline\Ai\Service\Style\StyleService;
use Weline\Framework\Manager\ObjectManager;

final class DesignDirectionService
{
    public const MODE_AUTO = StyleService::MODE_AUTO;
    public const MODE_MANUAL = StyleService::MODE_MANUAL;
    public const MODE_NONE = StyleService::MODE_NONE;
    public const BUILTIN_CARD_GAME_CODE = PageBuilderStyleProvider::CARD_GAME_STYLE_CODE;
    private const PAGEBUILDER_STYLE_ADAPTER_CODE = 'pagebuilder_plan_generation';

    public function __construct(
        private readonly ?StyleService $styleService = null
    ) {
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function listDirections(int $adminId, bool $includeDisabled = true): array
    {
        return $this->service()->listStyles($adminId, $includeDisabled);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDirection(string $code, int $adminId): ?array
    {
        return $this->service()->getStyle($code, $adminId);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function saveCustom(array $data, int $adminId): array
    {
        return $this->service()->saveCustom($data, $adminId);
    }

    /**
     * @return array<string, mixed>
     */
    public function disableCustom(string $code, int $adminId): array
    {
        return $this->service()->disableCustom($code, $adminId);
    }

    /**
     * @return array<string, mixed>
     */
    public function cloneBuiltin(string $code, int $adminId): array
    {
        return $this->service()->cloneBuiltin($code, $adminId);
    }

    /**
     * @return array{matched:bool,item:array<string,mixed>|null,score:int,matched_keywords:list<string>,reason:string}
     */
    public function matchDirection(string $title, string $brief, int $adminId): array
    {
        return $this->service()->matchStyle($title, $brief, $adminId, self::PAGEBUILDER_STYLE_ADAPTER_CODE);
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function resolveSelectionForScope(array $scope, int $adminId, bool $lock = false): array
    {
        return $this->service()->resolveSelectionForScope($scope, $adminId, $lock, self::PAGEBUILDER_STYLE_ADAPTER_CODE);
    }

    /**
     * @param array<string, mixed> $direction
     * @return array<string, mixed>
     */
    public function buildSnapshot(array $direction, string $reason = ''): array
    {
        return $this->service()->buildSnapshot($direction, $reason);
    }

    /**
     * @param array<string, mixed> $scope
     * @return list<string>
     */
    public function buildStageOnePromptLines(array $scope): array
    {
        return $this->service()->buildStageOnePromptLines($scope);
    }

    /**
     * @param array<string, mixed> $scope
     */
    public function buildStageThreePromptAddon(array $scope): string
    {
        return $this->service()->buildStageThreePromptAddon($scope);
    }

    /**
     * @param array<string, mixed> $scope
     * @return array<string, mixed>
     */
    public function buildWorkspaceDirectionState(array $scope): array
    {
        return $this->service()->buildWorkspaceStyleState($scope);
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public function snapshotHash(array $snapshot): string
    {
        return $this->service()->snapshotHash($snapshot);
    }

    public function normalizeMode(string $mode): string
    {
        return $this->service()->normalizeMode($mode);
    }

    private function service(): StyleService
    {
        return $this->styleService ?? ObjectManager::getInstance(StyleService::class);
    }
}

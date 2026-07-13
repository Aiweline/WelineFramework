<?php

declare(strict_types=1);

namespace Weline\Theme\Api\Layout;

/** Immutable layout identity exchanged across module boundaries. */
final readonly class LayoutIdentity
{
    public string $layoutOption;
    public string $scope;
    public string $targetType;
    public int $targetId;

    public function __construct(
        string $layoutOption = 'default',
        string $scope = 'default',
        string $targetType = 'global',
        int $targetId = 0,
    ) {
        $layoutOption = trim($layoutOption);
        $scope = trim($scope);
        $targetType = trim($targetType);

        $this->layoutOption = $layoutOption !== '' ? $layoutOption : 'default';
        $this->scope = $scope !== '' ? $scope : 'default';
        $this->targetType = $targetType !== '' ? $targetType : 'global';
        $this->targetId = max(0, $targetId);
    }

    /** @param array<string,mixed> $identity */
    public static function fromArray(array $identity): self
    {
        return new self(
            (string)($identity['layout_option'] ?? 'default'),
            (string)($identity['scope'] ?? 'default'),
            (string)($identity['target_type'] ?? $identity['theme_layout_target_type'] ?? 'global'),
            (int)($identity['target_id'] ?? $identity['theme_layout_target_id'] ?? 0),
        );
    }

    /** @return array{layout_option:string,scope:string,target_type:string,target_id:int} */
    public function toArray(): array
    {
        return [
            'layout_option' => $this->layoutOption,
            'scope' => $this->scope,
            'target_type' => $this->targetType,
            'target_id' => $this->targetId,
        ];
    }
}

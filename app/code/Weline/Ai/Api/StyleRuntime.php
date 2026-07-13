<?php

declare(strict_types=1);

namespace Weline\Ai\Api;

use Weline\Ai\Model\AiStyle;
use Weline\Ai\Service\Skill\AdapterSkillResolver;
use Weline\Ai\Service\Style\AdapterStyleResolver;
use Weline\Ai\Service\Style\StyleRegistry;
use Weline\Ai\Service\Style\StyleService;

/** Public facade for the internal style-selection implementation. */
final class StyleRuntime implements StyleRuntimeInterface
{
    public function __construct(
        private readonly StyleService $service,
        private readonly AdapterSkillResolver $skillResolver,
        private readonly AdapterStyleResolver $styleResolver,
        private readonly StyleRegistry $styleRegistry,
    ) {
    }

    public function resolveSelectionForScope(
        array $scope,
        int $adminId,
        bool $lock = false,
        string $adapterCode = ''
    ): array {
        return $this->service->resolveSelectionForScope($scope, $adminId, $lock, $adapterCode);
    }

    public function buildSkillCatalog(
        string $adapterCode,
        array $temporarySkillCodes = [],
        bool $includeInactive = false,
    ): array {
        return $this->skillResolver->buildSkillCatalog($adapterCode, $temporarySkillCodes, $includeInactive);
    }

    public function buildStyleCatalog(
        string $adapterCode,
        array $temporaryStyleCodes = [],
        int $adminId = 0,
        bool $includeInactive = false,
    ): array {
        return $this->styleResolver->buildStyleCatalog(
            $adapterCode,
            $temporaryStyleCodes,
            $adminId,
            $includeInactive,
        );
    }

    public function resolveStyleSnapshot(
        array $styleCodes,
        int $adminId,
        string $reason = 'Theme visual editor selection',
    ): array {
        $styleCode = $styleCodes[0] ?? '';
        if ($styleCode === '') {
            return [];
        }

        $style = $this->styleRegistry->getStyle($styleCode, $adminId, false);
        if (empty($style['exists']) || (string)($style['status'] ?? '') !== AiStyle::STATUS_ACTIVE) {
            return [];
        }

        return $this->service->buildSnapshot($style, $reason);
    }
}

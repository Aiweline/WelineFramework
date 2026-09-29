<?php

declare(strict_types=1);

namespace Weline\Theme\Service\LayoutEntity;

/**
 * @deprecated Prefer {@see ThemeLayoutEntityUpgradeSolidifyService} (§0 R5).
 * Kept as a thin alias so older DI/tests that type-hint purge still resolve.
 */
final class ThemeLayoutEntityUpgradePurgeService
{
    public function __construct(
        private readonly ThemeLayoutEntityUpgradeSolidifyService $solidifyService,
    ) {
    }

    /**
     * @param non-empty-string $invalidationReason
     * @return int solidified target count (legacy return was deleted nodes)
     */
    public function runOnce(string $invalidationReason): int
    {
        $report = $this->solidifyService->runOnce($invalidationReason);

        return (int)($report['solidified'] ?? 0);
    }

    /** @internal UT / fixture */
    public static function resetHasRunFlag(): void
    {
        ThemeLayoutEntityUpgradeSolidifyService::resetHasRunFlag();
    }
}

<?php

declare(strict_types=1);

namespace WeShop\Membership\Service;

use WeShop\Membership\Model\Membership;
use Weline\Framework\Http\Url;

class MembershipPageDataService
{
    public function __construct(
        private readonly MembershipService $membershipService,
        private readonly Url $url
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function build(int $customerId): array
    {
        $tiers = $this->getTierDefinitions();
        $membership = $this->membershipService->getCustomerMembership($customerId);

        $levelCode = strtolower((string) ($membership?->getData(Membership::schema_fields_LEVEL) ?? 'bronze'));
        $points = max(0, (int) ($membership?->getData(Membership::schema_fields_POINTS) ?? 0));
        if (!isset($tiers[$levelCode])) {
            $levelCode = 'bronze';
        }

        $currentTier = $tiers[$levelCode];
        $nextLevelCode = (string) ($currentTier['next'] ?? '');
        $nextTier = $nextLevelCode !== '' && isset($tiers[$nextLevelCode]) ? $tiers[$nextLevelCode] : null;

        $currentThreshold = (int) ($currentTier['threshold'] ?? 0);
        $nextThreshold = (int) ($nextTier['threshold'] ?? $currentThreshold);
        $isTopTier = $nextTier === null;
        $pointsToNext = $isTopTier ? 0 : max(0, $nextThreshold - $points);

        return [
            'membership' => [
                'level_code' => $levelCode,
                'level_label' => (string) ($currentTier['label'] ?? ''),
                'points' => $points,
                'next_level_code' => $nextLevelCode,
                'next_level_label' => (string) ($nextTier['label'] ?? ''),
                'points_to_next' => $pointsToNext,
                'progress_percent' => $this->calculateProgress($points, $currentThreshold, $nextThreshold, $isTopTier),
                'is_top_tier' => $isTopTier,
            ],
            'benefits' => array_values((array) ($currentTier['benefits'] ?? [])),
            'tiers' => $this->buildTierCards($tiers, $levelCode, $points),
            'membership_url' => $this->url->getUrl('membership'),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function getTierDefinitions(): array
    {
        return [
            'bronze' => [
                'label' => (string) __('Bronze'),
                'threshold' => 0,
                'next' => 'silver',
                'benefits' => [
                    (string) __('Member pricing entry tier'),
                    (string) __('Order tracking and standard support'),
                ],
            ],
            'silver' => [
                'label' => (string) __('Silver'),
                'threshold' => 200,
                'next' => 'gold',
                'benefits' => [
                    (string) __('Priority customer support'),
                    (string) __('Early campaign access'),
                ],
            ],
            'gold' => [
                'label' => (string) __('Gold'),
                'threshold' => 600,
                'next' => 'platinum',
                'benefits' => [
                    (string) __('Faster shipping promotions'),
                    (string) __('Extended return window'),
                ],
            ],
            'platinum' => [
                'label' => (string) __('Platinum'),
                'threshold' => 1200,
                'next' => '',
                'benefits' => [
                    (string) __('Dedicated account assistance'),
                    (string) __('VIP campaign previews'),
                ],
            ],
        ];
    }

    private function calculateProgress(int $points, int $currentThreshold, int $nextThreshold, bool $isTopTier): int
    {
        if ($isTopTier) {
            return 100;
        }

        $window = max(1, $nextThreshold - $currentThreshold);
        $currentPoints = min(max($points - $currentThreshold, 0), $window);

        return (int) round(($currentPoints / $window) * 100);
    }

    /**
     * @param array<string, array<string, mixed>> $tiers
     * @return array<int, array<string, mixed>>
     */
    private function buildTierCards(array $tiers, string $currentLevelCode, int $points): array
    {
        $cards = [];
        foreach ($tiers as $code => $tier) {
            $threshold = (int) ($tier['threshold'] ?? 0);
            $cards[] = [
                'code' => $code,
                'label' => (string) ($tier['label'] ?? ''),
                'threshold' => $threshold,
                'is_current' => $code === $currentLevelCode,
                'is_unlocked' => $points >= $threshold,
            ];
        }

        return $cards;
    }
}

